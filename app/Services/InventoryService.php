<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\CrmNotification;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\InventoryStock;
use App\Models\InventoryTransferItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class InventoryService
{
    public function __construct(
        private readonly CrmNotificationService $notifications,
        private readonly InventoryItemResolver $items,
    ) {}

    /**
     * Authoritative stock mutation for the multibranch inventory.
     */
    public function moveAtBranch(
        Branch $branch,
        Product $product,
        string $type,
        ?int $quantity,
        ?int $newStock,
        ?User $performedBy = null,
        ?string $reason = null,
        ?string $notes = null,
        ?ProductVariant $variant = null,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?InventoryTransferItem $transferItem = null,
        ?InventoryItem $inventoryItem = null,
    ): InventoryMovement {
        if (! in_array($type, InventoryMovement::types(), true)) {
            throw ValidationException::withMessages(['type' => 'El tipo de movimiento de inventario no es válido.']);
        }

        $isTransfer = in_array($type, [
            InventoryMovement::TYPE_TRANSFER_OUT,
            InventoryMovement::TYPE_TRANSFER_IN,
        ], true);

        if ($isTransfer !== (bool) $transferItem) {
            throw ValidationException::withMessages([
                'type' => 'Los movimientos de transferencia requieren su ítem de transferencia.',
            ]);
        }

        [$movement, $stock] = DB::transaction(function () use ($branch, $product, $variant, $type, $quantity, $newStock, $performedBy, $reason, $notes, $referenceType, $referenceId, $transferItem, $inventoryItem) {
            $lockedBranch = Branch::query()->whereKey($branch->id)->lockForUpdate()->first();
            $canReverseHistoricalSale = $type === InventoryMovement::TYPE_SALE_REVERSAL;
            if (! $lockedBranch || (! $lockedBranch->is_active && ! $canReverseHistoricalSale)) {
                throw ValidationException::withMessages(['branch_id' => 'La sede seleccionada no está activa.']);
            }

            $item = $inventoryItem ?? $this->items->resolve($product, $variant);
            $this->assertInventoryItemMatches($item, $product, $variant);
            $stock = $this->lockOrCreateStock($lockedBranch, $item);
            $stockBefore = (int) $stock->quantity;
            $delta = $this->calculateQuantityDelta($type, $quantity, $newStock, $stockBefore);

            if ($delta === 0) {
                throw ValidationException::withMessages(['quantity' => 'El movimiento no cambia el stock actual.']);
            }

            $stockAfter = $stockBefore + $delta;
            if ($stockAfter < 0) {
                throw ValidationException::withMessages(['quantity' => 'No hay stock suficiente para realizar el movimiento.']);
            }

            $stock->update(['quantity' => $stockAfter]);

            $movement = InventoryMovement::create([
                'branch_id' => $lockedBranch->id,
                'inventory_item_id' => $item->id,
                'inventory_transfer_item_id' => $transferItem?->id,
                'product_id' => $product->id,
                'product_variant_id' => $variant?->id,
                'created_by' => $performedBy?->id,
                'type' => $type,
                'quantity_delta' => $delta,
                'stock_before' => $stockBefore,
                'stock_after' => $stockAfter,
                'reason' => $reason,
                'notes' => $notes,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
            ]);

            return [$movement, $stock->fresh()];
        });

        $this->notifyBranchStockTransition($movement, $stock, $branch, $product, $variant);

        return $movement;
    }

    public function updateMinimumStock(
        Branch $branch,
        Product $product,
        int $minimumQuantity,
        ?User $performedBy = null,
        ?ProductVariant $variant = null,
    ): InventoryStock {
        if ($minimumQuantity < 0) {
            throw ValidationException::withMessages([
                'minimum_quantity' => 'El stock mínimo no puede ser negativo.',
            ]);
        }

        return DB::transaction(function () use ($branch, $product, $variant, $minimumQuantity) {
            $lockedBranch = Branch::query()->whereKey($branch->id)->lockForUpdate()->first();
            if (! $lockedBranch?->is_active) {
                throw ValidationException::withMessages(['branch_id' => 'La sede seleccionada no está activa.']);
            }

            $item = $this->items->resolve($product, $variant);
            $stock = $this->lockOrCreateStock($lockedBranch, $item);
            $stock->update(['minimum_quantity' => $minimumQuantity]);

            return $stock->fresh(['branch', 'inventoryItem.product', 'inventoryItem.productVariant.product']);
        });
    }

    private function lockOrCreateStock(Branch $branch, InventoryItem $item): InventoryStock
    {
        $timestamp = now();
        InventoryStock::query()->insertOrIgnore([
            'branch_id' => $branch->id,
            'inventory_item_id' => $item->id,
            'quantity' => 0,
            'minimum_quantity' => 0,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        return InventoryStock::query()
            ->where('branch_id', $branch->id)
            ->where('inventory_item_id', $item->id)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function assertInventoryItemMatches(
        InventoryItem $item,
        Product $product,
        ?ProductVariant $variant,
    ): void {
        $matches = $variant
            ? $item->product_id === null && (int) $item->product_variant_id === (int) $variant->id
            : (int) $item->product_id === (int) $product->id && $item->product_variant_id === null;

        if (! $matches) {
            throw ValidationException::withMessages([
                'inventory_item_id' => 'La unidad inventariable no corresponde al producto de la operación.',
            ]);
        }
    }

    private function notifyBranchStockTransition(
        InventoryMovement $movement,
        InventoryStock $stock,
        Branch $branch,
        Product $product,
        ?ProductVariant $variant,
    ): void {
        $before = $this->stockState($movement->stock_before, (int) $stock->minimum_quantity);
        $after = $this->stockState($movement->stock_after, (int) $stock->minimum_quantity);
        $enteredLow = $before === 'normal' && $after === 'low';
        $enteredOut = $before !== 'out' && $after === 'out';

        if (! $enteredLow && ! $enteredOut) {
            return;
        }

        $type = $after === 'out' ? CrmNotification::TYPE_STOCK_OUT : CrmNotification::TYPE_STOCK_LOW;
        $name = $variant ? "{$product->name} / {$variant->display_name}" : $product->name;
        $sku = $variant?->sku ?? $product->sku;

        try {
            $this->notifications->distribute('inventory.view', [
                'type' => $type,
                'severity' => $after === 'out' ? CrmNotification::SEVERITY_DANGER : CrmNotification::SEVERITY_WARNING,
                'title' => $after === 'out' ? 'Producto agotado' : 'Stock bajo',
                'message' => $after === 'out'
                    ? "{$name} quedó agotado en {$branch->code}."
                    : "{$name} alcanzó el nivel mínimo de stock en {$branch->code}.",
                'data' => [
                    'branch_id' => $branch->id,
                    'product_id' => $product->id,
                    'product_variant_id' => $variant?->id,
                    'sku' => $sku,
                    'stock' => $movement->stock_after,
                    'minimum_stock' => (int) $stock->minimum_quantity,
                ],
                'reference_type' => $variant ? 'product_variant' : 'product',
                'reference_id' => $variant?->id ?? $product->id,
                'dedupe_key' => "{$type}:movement:{$movement->id}",
            ]);
        } catch (\Throwable $exception) {
            Log::warning('No se pudo crear la alerta CRM de inventario por sede.', [
                'movement_id' => $movement->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function stockState(int $stock, int $minimumStock): string
    {
        if ($stock <= 0) {
            return 'out';
        }

        return $stock <= $minimumStock ? 'low' : 'normal';
    }

    private function calculateQuantityDelta(string $type, ?int $quantity, ?int $newStock, int $stockBefore): int
    {
        return match ($type) {
            InventoryMovement::TYPE_ENTRY,
            InventoryMovement::TYPE_SALE_REVERSAL,
            InventoryMovement::TYPE_TRANSFER_IN => (int) $quantity,
            InventoryMovement::TYPE_EXIT,
            InventoryMovement::TYPE_SALE,
            InventoryMovement::TYPE_TRANSFER_OUT => -1 * (int) $quantity,
            default => $newStock === null
                ? throw ValidationException::withMessages(['new_stock' => 'Debes indicar el nuevo stock para hacer un ajuste.'])
                : $newStock - $stockBefore,
        };
    }
}
