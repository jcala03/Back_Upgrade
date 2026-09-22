<?php

namespace App\Services;

use App\Models\InventoryItem;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;

class InventoryItemResolver
{
    public function resolve(Product $product, ?ProductVariant $variant = null): InventoryItem
    {
        return $this->resolveSelection($product, $variant, true);
    }

    /**
     * Resolve the exact catalog selection frozen on a commercial document.
     * Later catalog changes must not redirect its branch stock identity.
     */
    public function resolveFrozen(Product $product, ?ProductVariant $variant = null): InventoryItem
    {
        return $this->resolveSelection($product, $variant, false);
    }

    private function resolveSelection(
        Product $product,
        ?ProductVariant $variant,
        bool $enforceCurrentSelection,
    ): InventoryItem {
        if ($variant) {
            $belongsToProduct = ProductVariant::query()
                ->whereKey($variant->id)
                ->where('product_id', $product->id)
                ->exists();

            if (! $belongsToProduct) {
                throw ValidationException::withMessages([
                    'product_variant_id' => 'La variante no pertenece al producto indicado.',
                ]);
            }
        } elseif ($enforceCurrentSelection && $product->variants()->where('is_active', true)->exists()) {
            throw ValidationException::withMessages([
                'product_variant_id' => 'Este producto maneja variantes. Selecciona una variante.',
            ]);
        }

        $identity = $variant
            ? ['product_id' => null, 'product_variant_id' => $variant->id]
            : ['product_id' => $product->id, 'product_variant_id' => null];

        try {
            return InventoryItem::query()->firstOrCreate($identity);
        } catch (QueryException $exception) {
            $existing = InventoryItem::query()->where($identity)->first();

            if ($existing) {
                return $existing;
            }

            throw $exception;
        }
    }
}
