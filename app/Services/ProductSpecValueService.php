<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductCategoryField;
use App\Models\ProductSpecValue;

class ProductSpecValueService
{
    public function sync(Product $product, array $technicalSpecs): void
    {
        $product->specValues()->delete();

        if (! $product->category_id) {
            return;
        }

        $fields = ProductCategoryField::query()
            ->where('product_category_id', $product->category_id)
            ->where('scope', ProductCategoryField::SCOPE_PRODUCT)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        foreach ($fields as $field) {
            if (! array_key_exists($field->field_key, $technicalSpecs)) {
                continue;
            }

            $value = $technicalSpecs[$field->field_key];

            if ($value === null || $value === '') {
                continue;
            }

            ProductSpecValue::create([
                'product_id' => $product->id,
                'product_category_field_id' => $field->id,
                ...$this->buildValuePayload($field, $value),
            ]);
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

            return $payload;
        }

        if ($field->type === ProductCategoryField::TYPE_NUMBER) {
            $payload['value_number'] = (float) $value;

            return $payload;
        }

        $payload['value_text'] = trim((string) $value);

        return $payload;
    }
}
