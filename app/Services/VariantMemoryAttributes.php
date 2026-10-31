<?php

namespace App\Services;

use App\Models\ProductCategory;
use Illuminate\Validation\ValidationException;

/** Technical metadata only: these values do not certify physical compatibility. */
class VariantMemoryAttributes
{
    public const CATEGORY = 'pantallas-android';

    public const FIELDS = ['ram_gb' => 'RAM', 'almacenamiento_gb' => 'Almacenamiento'];

    public static function applies(?ProductCategory $category): bool
    {
        return $category?->slug === self::CATEGORY;
    }

    public static function valid(mixed $value): bool
    {
        return ! is_bool($value) && is_scalar($value) && is_numeric($value)
            && is_finite((float) $value) && (float) $value > 0
            && (float) $value <= 9999999999.99
            && floor((float) $value) === (float) $value;
    }

    /** Add only absent definitions; never replace a category's complete field list. */
    public function prepare(ProductCategory $category): void
    {
        if (! self::applies($category)) {
            throw ValidationException::withMessages(['category' => 'Selecciona la categoría Pantallas Android.']);
        }

        // Validate every existing definition before inserting anything.
        foreach (self::FIELDS as $key => $name) {
            $field = $category->fields()->where('field_key', $key)->first();
            if ($field && ($field->scope !== 'variant' || $field->type !== 'number'
                || ! $field->is_active || $field->is_required || $field->filter_unit !== 'GB')) {
                throw ValidationException::withMessages([$key => 'La definición existente no coincide con un campo opcional, numérico, activo, de variante y unidad GB. Revísala sin sobrescribirla.']);
            }
        }

        foreach (self::FIELDS as $key => $name) {
            $category->fields()->firstOrCreate(['field_key' => $key], [
                'name' => $name, 'type' => 'number', 'scope' => 'variant',
                'filter_unit' => 'GB', 'is_active' => true, 'is_required' => false,
                'is_filterable' => false, 'sort_order' => $key === 'ram_gb' ? 90 : 91,
            ]);
        }
    }
}
