<?php

namespace App\Services;

use App\Models\ProductVariant;
use Illuminate\Validation\ValidationException;

class VariantTechnicalReadiness
{
    public function assess(ProductVariant $variant, ?bool $requiresOem = null): array
    {
        $variant->loadMissing(['product.productCategory', 'specValues.field', 'vehicleCompatibilities']);
        $required = $requiresOem ?? (bool) $variant->product?->productCategory?->requires_oem_compatibility;
        $issues = [];
        foreach (VariantMemoryAttributes::FIELDS as $key => $label) {
            $value = $variant->specs[$key] ?? null;
            if ($value === null || $value === '') {
                $issues[$key] = "Falta {$label} en GB.";
            } elseif (! VariantMemoryAttributes::valid($value)) {
                $issues[$key] = "{$label} debe ser un entero positivo en GB.";
            }
        }
        if ($required) {
            if ($variant->resolvedCompatibilityType() !== 'vehicle_specific') {
                $issues['compatibility'] = 'Se requiere compatibilidad vehicular/OEM específica.';
            }
            try {
                app(ProductVariantCompatibilityService::class)->validateSelection(
                    'vehicle_specific', $variant->vehicleCompatibilities->toArray(), 'compatibility', true
                );
            } catch (ValidationException $exception) {
                foreach ($exception->errors() as $key => $messages) {
                    $issues[$key] = $messages[0];
                }
            }
        }

        return ['structurally_ready' => $issues === [], 'issues' => $issues,
            'oem_required' => $required, 'certifies_physical_compatibility' => false];
    }
}
