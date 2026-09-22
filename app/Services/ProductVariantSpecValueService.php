<?php

namespace App\Services;

use App\Models\ProductCategoryField;
use App\Models\ProductVariant;
use App\Models\ProductVariantSpecValue;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class ProductVariantSpecValueService
{
    public function sync(ProductVariant $variant, array $specs): void
    {
        $fields = $this->variantFields($variant);
        $this->validateSpecs($fields, $specs);

        $variant->specValues()->delete();

        foreach ($fields as $field) {
            if (! array_key_exists($field->field_key, $specs)) {
                continue;
            }

            $value = $specs[$field->field_key];

            if ($value === null || $value === '') {
                continue;
            }

            ProductVariantSpecValue::create([
                'product_variant_id' => $variant->id,
                'product_category_field_id' => $field->id,
                ...$this->buildValuePayload($field, $value),
            ]);
        }
    }

    private function variantFields(ProductVariant $variant): Collection
    {
        $categoryId = $variant->product?->category_id;

        if (! $categoryId) {
            return collect();
        }

        return ProductCategoryField::query()
            ->where('product_category_id', $categoryId)
            ->where('scope', ProductCategoryField::SCOPE_VARIANT)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();
    }

    private function validateSpecs(Collection $fields, array $specs): void
    {
        $fieldsByKey = $fields->keyBy('field_key');

        foreach ($specs as $fieldKey => $value) {
            $field = $fieldsByKey->get($fieldKey);

            if (! $field) {
                throw ValidationException::withMessages([
                    "specs.{$fieldKey}" => 'El campo no existe, está inactivo o no aplica a variantes.',
                ]);
            }

            if (
                $field->type === ProductCategoryField::TYPE_SELECT
                && $value !== null
                && $value !== ''
                && ! in_array((string) $value, $field->options ?? [], true)
            ) {
                throw ValidationException::withMessages([
                    "specs.{$fieldKey}" => 'El valor seleccionado no está permitido para este campo.',
                ]);
            }

            if (
                $field->type === ProductCategoryField::TYPE_NUMBER
                && $value !== null
                && $value !== ''
                && ! is_numeric($value)
            ) {
                throw ValidationException::withMessages([
                    "specs.{$fieldKey}" => 'El valor debe ser numérico.',
                ]);
            }
        }

        foreach ($fields->where('is_required', true) as $field) {
            $value = $specs[$field->field_key] ?? null;

            if ($value === null || $value === '') {
                throw ValidationException::withMessages([
                    "specs.{$field->field_key}" => 'Este campo de variante es obligatorio.',
                ]);
            }
        }
    }

    private function buildValuePayload(ProductCategoryField $field, mixed $value): array
    {
        $payload = [
            'value_text' => null,
            'value_number' => null,
            'value_boolean' => null,
        ];

        if ($field->type === ProductCategoryField::TYPE_BOOLEAN) {
            $payload['value_boolean'] = filter_var(
                $value,
                FILTER_VALIDATE_BOOLEAN,
                FILTER_NULL_ON_FAILURE
            ) ?? false;
        } elseif ($field->type === ProductCategoryField::TYPE_NUMBER) {
            $payload['value_number'] = (float) $value;
        } else {
            $payload['value_text'] = trim((string) $value);
        }

        return $payload;
    }
}
