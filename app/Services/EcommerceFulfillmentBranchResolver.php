<?php

namespace App\Services;

use App\Exceptions\EcommerceShippingException;
use App\Models\Branch;
use App\Models\InventoryItem;
use App\Models\InventoryStock;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Support\Collection;

class EcommerceFulfillmentBranchResolver
{
    /** @return Collection<int, Branch> */
    public function candidates(Order $order): Collection
    {
        $requirements = $this->requirements($order);
        $branches = Branch::query()
            ->where('is_active', true)
            ->orderBy('ecommerce_priority')
            ->orderBy('id')
            ->get();

        if ($requirements->isEmpty()) {
            return $branches;
        }

        $stocks = InventoryStock::query()
            ->whereIn('branch_id', $branches->modelKeys())
            ->whereIn('inventory_item_id', $requirements->keys())
            ->get()
            ->keyBy(fn (InventoryStock $stock) => $stock->branch_id.':'.$stock->inventory_item_id);

        return $branches->filter(function (Branch $branch) use ($requirements, $stocks): bool {
            foreach ($requirements as $inventoryItemId => $quantity) {
                $stock = $stocks->get($branch->id.':'.$inventoryItemId);
                if (! $stock || (int) $stock->quantity < $quantity) {
                    return false;
                }
            }

            return true;
        })->values();
    }

    public function select(Order $order): Branch
    {
        $branch = $this->candidates($order)->first();

        if (! $branch) {
            throw new EcommerceShippingException(
                EcommerceShippingException::NO_BRANCH_CAN_FULFILL,
                'Ninguna sede activa puede surtir todos los productos de la orden.',
                ['order_id' => $order->id],
            );
        }

        return $branch;
    }

    public function revalidateUnderLock(Order $order, Branch $branch): Branch
    {
        $lockedBranch = Branch::query()->whereKey($branch->id)->lockForUpdate()->first();
        if (! $lockedBranch?->is_active) {
            $this->staleStock($order, $branch);
        }

        foreach ($this->requirements($order)->sortKeys() as $inventoryItemId => $quantity) {
            $stock = InventoryStock::query()
                ->where('branch_id', $lockedBranch->id)
                ->where('inventory_item_id', $inventoryItemId)
                ->lockForUpdate()
                ->first();

            if (! $stock || (int) $stock->quantity < $quantity) {
                $this->staleStock($order, $lockedBranch, (int) $inventoryItemId);
            }
        }

        return $lockedBranch;
    }

    /** @return Collection<int, int> inventory_item_id => required quantity */
    private function requirements(Order $order): Collection
    {
        $order->loadMissing(['items.product', 'items.productVariant']);
        $requirements = collect();

        foreach ($order->items as $item) {
            if (($item->item_type ?? OrderItem::ITEM_TYPE_PRODUCT) !== OrderItem::ITEM_TYPE_PRODUCT) {
                continue;
            }

            if (! $item->product) {
                return collect([-1 => PHP_INT_MAX]);
            }

            if (! $item->product->requires_shipping) {
                continue;
            }

            $inventoryItem = $this->inventoryItem($item);
            if (! $inventoryItem) {
                return collect([-1 => PHP_INT_MAX]);
            }

            $requirements->put(
                $inventoryItem->id,
                (int) $requirements->get($inventoryItem->id, 0) + (int) $item->quantity,
            );
        }

        return $requirements;
    }

    private function inventoryItem(OrderItem $item): ?InventoryItem
    {
        return $item->product_variant_id
            ? InventoryItem::query()
                ->whereNull('product_id')
                ->where('product_variant_id', $item->product_variant_id)
                ->first()
            : InventoryItem::query()
                ->where('product_id', $item->product_id)
                ->whereNull('product_variant_id')
                ->first();
    }

    private function staleStock(Order $order, Branch $branch, ?int $inventoryItemId = null): never
    {
        throw new EcommerceShippingException(
            EcommerceShippingException::STALE_STOCK,
            'La sede seleccionada ya no tiene stock suficiente para surtir toda la orden.',
            array_filter([
                'order_id' => $order->id,
                'branch_id' => $branch->id,
                'inventory_item_id' => $inventoryItemId,
            ], fn ($value) => $value !== null),
        );
    }
}
