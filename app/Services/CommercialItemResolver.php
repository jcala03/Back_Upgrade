<?php

namespace App\Services;

use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Service;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class CommercialItemResolver
{
    public function __construct(private readonly ProductPricingService $pricing) {}

    /**
     * The third argument is retained temporarily for source compatibility.
     * Commercial availability is authoritative only at Order confirmation,
     * against the InventoryStock row for the Order branch.
     */
    public function resolve(array $items, bool $admin, bool $validateStock = false): array
    {
        $resolved = [];

        foreach ($items as $requestItem) {
            $type = $requestItem['item_type'] ?? OrderItem::ITEM_TYPE_PRODUCT;
            if ($type === OrderItem::ITEM_TYPE_SERVICE) {
                $this->resolveServiceItem($resolved, $requestItem, $admin);
            } else {
                $this->resolveProductItem($resolved, $requestItem, $admin);
            }
        }

        ksort($resolved);

        return array_values($resolved);
    }

    public function resolveFrozen(Collection $items): array
    {
        $resolved = [];

        foreach ($items as $item) {
            $type = $item->item_type ?? OrderItem::ITEM_TYPE_PRODUCT;
            if ($type === OrderItem::ITEM_TYPE_SERVICE) {
                $service = Service::query()->commerciallyAvailable()->find($item->service_id);
                if (! $service) {
                    throw ValidationException::withMessages(['items' => 'Un servicio cotizado ya no existe o no está activo.']);
                }
                $key = "service:{$service->id}";
                $quantity = ($resolved[$key]['quantity'] ?? 0) + (int) $item->quantity;
                $subtotal = (int) $item->unit_price * $quantity;
                $discount = ($resolved[$key]['discount_amount'] ?? 0) + (int) $item->discount_amount;
                $this->validateDiscount($discount, $subtotal);
                $resolved[$key] = [
                    'item_type' => OrderItem::ITEM_TYPE_SERVICE,
                    'product_id' => null,
                    'product_variant_id' => null,
                    'service_id' => $service->id,
                    'product_name' => null,
                    'product_slug' => null,
                    'product_sku' => null,
                    'variant_name' => null,
                    'variant_sku' => null,
                    'variant_specs' => null,
                    'service_name' => $item->service_name,
                    'service_description' => $item->service_description,
                    'unit_price' => (int) $item->unit_price,
                    'unit_cost' => $item->unit_cost === null ? null : (int) $item->unit_cost,
                    'quantity' => $quantity,
                    'subtotal' => $subtotal,
                    'discount_amount' => $discount,
                    'total' => $subtotal - $discount,
                ];

                continue;
            }

            $product = Product::query()->whereKey($item->product_id)->where('is_active', true)->first();
            if (! $product) {
                throw ValidationException::withMessages(['items' => 'Un producto cotizado ya no existe o no está activo.']);
            }

            $variant = null;
            if ($item->product_variant_id) {
                $variant = ProductVariant::query()
                    ->whereKey($item->product_variant_id)
                    ->where('product_id', $product->id)
                    ->where('is_active', true)
                    ->first();
                if (! $variant) {
                    throw ValidationException::withMessages(['items' => 'Una variante cotizada ya no pertenece al producto o no está activa.']);
                }
            }

            $key = 'product:'.$product->id.':'.($variant?->id ?? 'product');
            $quantity = ($resolved[$key]['quantity'] ?? 0) + (int) $item->quantity;

            $subtotal = (int) $item->unit_price * $quantity;
            $discount = ($resolved[$key]['discount_amount'] ?? 0) + (int) $item->discount_amount;
            $this->validateDiscount($discount, $subtotal);
            $resolved[$key] = [
                'item_type' => OrderItem::ITEM_TYPE_PRODUCT,
                'product_id' => $product->id,
                'product_variant_id' => $variant?->id,
                'service_id' => null,
                'product_name' => $item->product_name,
                'product_slug' => $item->product_slug,
                'product_sku' => $item->product_sku,
                'variant_name' => $item->variant_name,
                'variant_sku' => $item->variant_sku,
                'variant_specs' => $item->variant_specs,
                'service_name' => null,
                'service_description' => null,
                'unit_price' => (int) $item->unit_price,
                'unit_cost' => $item->unit_cost === null ? null : (int) $item->unit_cost,
                'quantity' => $quantity,
                'subtotal' => $subtotal,
                'discount_amount' => $discount,
                'total' => $subtotal - $discount,
            ];
        }

        ksort($resolved);

        return array_values($resolved);
    }

    private function resolveProductItem(array &$resolved, array $requestItem, bool $admin): void
    {
        [$product, $variant] = $this->resolveCatalogSelection($requestItem, $admin);
        $key = 'product:'.$product->id.':'.($variant?->id ?? 'product');
        $quantity = (int) $requestItem['quantity'];
        $existingQuantity = ($resolved[$key]['quantity'] ?? 0) + $quantity;

        $pricing = $this->pricing->calculate(($variant ?? $product)->getAttributes());
        $unitPrice = (int) $pricing['price'];
        $lineSubtotal = $unitPrice * $existingQuantity;
        $discount = ($resolved[$key]['discount_amount'] ?? 0) + ($admin ? (int) ($requestItem['discount_amount'] ?? 0) : 0);
        $this->validateDiscount($discount, $lineSubtotal);

        $resolved[$key] = [
            'item_type' => OrderItem::ITEM_TYPE_PRODUCT,
            'product_id' => $product->id,
            'product_variant_id' => $variant?->id,
            'service_id' => null,
            'product_name' => $product->name,
            'product_slug' => $product->slug,
            'product_sku' => $product->sku,
            'variant_name' => $variant?->display_name,
            'variant_sku' => $variant?->sku,
            'variant_specs' => $variant?->specs,
            'service_name' => null,
            'service_description' => null,
            'unit_price' => $unitPrice,
            'unit_cost' => (int) $pricing['total_cost'],
            'quantity' => $existingQuantity,
            'subtotal' => $lineSubtotal,
            'discount_amount' => $discount,
            'total' => $lineSubtotal - $discount,
        ];
    }

    private function resolveServiceItem(array &$resolved, array $requestItem, bool $admin): void
    {
        if (! $admin) {
            throw ValidationException::withMessages(['items' => 'Los servicios solo están disponibles en operaciones CRM.']);
        }
        $service = Service::query()->commerciallyAvailable()->find($requestItem['service_id']);
        if (! $service) {
            throw ValidationException::withMessages(['items' => 'Uno o más servicios no están disponibles.']);
        }
        $key = "service:{$service->id}";
        $quantity = ($resolved[$key]['quantity'] ?? 0) + (int) $requestItem['quantity'];
        $subtotal = (int) $service->price * $quantity;
        $discount = ($resolved[$key]['discount_amount'] ?? 0) + (int) ($requestItem['discount_amount'] ?? 0);
        $this->validateDiscount($discount, $subtotal);
        $resolved[$key] = [
            'item_type' => OrderItem::ITEM_TYPE_SERVICE,
            'product_id' => null,
            'product_variant_id' => null,
            'service_id' => $service->id,
            'product_name' => null,
            'product_slug' => null,
            'product_sku' => null,
            'variant_name' => null,
            'variant_sku' => null,
            'variant_specs' => null,
            'service_name' => $service->name,
            'service_description' => $service->description,
            'unit_price' => (int) $service->price,
            'unit_cost' => $service->cost === null ? null : (int) $service->cost,
            'quantity' => $quantity,
            'subtotal' => $subtotal,
            'discount_amount' => $discount,
            'total' => $subtotal - $discount,
        ];
    }

    private function resolveCatalogSelection(array $item, bool $admin): array
    {
        $product = Product::query()
            ->whereKey($item['product_id'])
            ->where('is_active', true)
            ->when(! $admin, fn ($query) => $query->where('is_visible', true))
            ->first();
        if (! $product) {
            throw ValidationException::withMessages(['items' => 'Uno o más productos no están disponibles.']);
        }

        $variant = null;
        if (! empty($item['product_variant_id'])) {
            $variant = ProductVariant::query()
                ->with('specValues.field')
                ->whereKey($item['product_variant_id'])
                ->where('product_id', $product->id)
                ->where('is_active', true)
                ->when(! $admin, fn ($query) => $query->where('is_visible', true))
                ->first();
            if (! $variant) {
                throw ValidationException::withMessages(['items' => 'Una variante no pertenece al producto o no está disponible.']);
            }
        } else {
            $activeVariants = $product->variants()->where('is_active', true)
                ->when(! $admin, fn ($query) => $query->where('is_visible', true));
            if ($activeVariants->exists()) {
                $variant = (clone $activeVariants)->with('specValues.field')->where('is_default', true)->first();
                if (! $variant) {
                    throw ValidationException::withMessages(['items' => "Debes seleccionar una variante para {$product->name}."]);
                }
            }
        }

        return [$product, $variant];
    }

    private function validateDiscount(int $discount, int $subtotal): void
    {
        if ($discount > $subtotal) {
            throw ValidationException::withMessages(['items' => 'El descuento no puede superar el subtotal de la línea.']);
        }
    }
}
