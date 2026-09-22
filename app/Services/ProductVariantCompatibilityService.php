<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\VehicleModel;
use App\Models\VehicleMultimediaSystem;
use App\Models\VehicleVersion;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class ProductVariantCompatibilityService
{
    public function validateSelection(
        ?string $compatibilityType,
        array $compatibilities,
        string $path = 'vehicle_compatibilities'
    ): void {
        if (
            $compatibilityType === Product::COMPATIBILITY_TYPE_VEHICLE_SPECIFIC
            && count($compatibilities) === 0
        ) {
            throw ValidationException::withMessages([
                $path => 'Selecciona al menos un vehículo compatible para esta variante.',
            ]);
        }

        foreach ($compatibilities as $index => $compatibility) {
            $this->validateCompatibility($compatibility, "{$path}.{$index}");
        }
    }

    public function sync(ProductVariant $variant, array $compatibilities): void
    {
        $variant->vehicleCompatibilities()->delete();

        $this->uniqueCompatibilities($compatibilities)->each(
            fn (array $compatibility) => $variant->vehicleCompatibilities()->create([
                'vehicle_brand_id' => $compatibility['vehicle_brand_id'],
                'vehicle_model_id' => $compatibility['vehicle_model_id'] ?? null,
                'vehicle_version_id' => $compatibility['vehicle_version_id'] ?? null,
                'vehicle_multimedia_system_id' => $compatibility['vehicle_multimedia_system_id'] ?? null,
                'year_from' => $compatibility['year_from'] ?? null,
                'year_to' => $compatibility['year_to'] ?? null,
                'notes' => $this->nullableTrim($compatibility['notes'] ?? null),
            ])
        );
    }

    private function validateCompatibility(array $compatibility, string $path): void
    {
        $brandId = (int) ($compatibility['vehicle_brand_id'] ?? 0);
        $modelId = $compatibility['vehicle_model_id'] ?? null;
        $versionId = $compatibility['vehicle_version_id'] ?? null;
        $systemId = $compatibility['vehicle_multimedia_system_id'] ?? null;
        $yearFrom = $compatibility['year_from'] ?? null;
        $yearTo = $compatibility['year_to'] ?? null;

        $model = $modelId ? VehicleModel::find($modelId) : null;
        if ($model && (int) $model->vehicle_brand_id !== $brandId) {
            throw ValidationException::withMessages([
                "{$path}.vehicle_model_id" => 'El modelo seleccionado no pertenece a la marca del vehículo.',
            ]);
        }

        if ($versionId && ! $modelId) {
            throw ValidationException::withMessages([
                "{$path}.vehicle_version_id" => 'Para seleccionar una generación debes seleccionar primero el modelo.',
            ]);
        }

        $version = $versionId ? VehicleVersion::find($versionId) : null;
        if ($version && (int) $version->vehicle_model_id !== (int) $modelId) {
            throw ValidationException::withMessages([
                "{$path}.vehicle_version_id" => 'La generación seleccionada no pertenece al modelo del vehículo.',
            ]);
        }

        $system = $systemId ? VehicleMultimediaSystem::find($systemId) : null;
        if ($system && (int) $system->vehicle_brand_id !== $brandId) {
            throw ValidationException::withMessages([
                "{$path}.vehicle_multimedia_system_id" => 'El sistema multimedia no pertenece a la marca del vehículo.',
            ]);
        }

        if ($yearFrom !== null && $yearTo !== null && (int) $yearFrom > (int) $yearTo) {
            throw ValidationException::withMessages([
                "{$path}.year_to" => 'El año final debe ser mayor o igual al año inicial.',
            ]);
        }

        if ($version) {
            if ($yearFrom !== null && (int) $yearFrom < (int) $version->year_from) {
                throw ValidationException::withMessages([
                    "{$path}.year_from" => 'El año inicial no puede ser anterior al rango de la versión.',
                ]);
            }

            if ($yearTo !== null && (int) $yearTo < (int) $version->year_from) {
                throw ValidationException::withMessages([
                    "{$path}.year_to" => 'El año final no puede ser anterior al rango de la versión.',
                ]);
            }

            if ($yearFrom !== null && $version->year_to !== null && (int) $yearFrom > (int) $version->year_to) {
                throw ValidationException::withMessages([
                    "{$path}.year_from" => 'El año inicial no puede superar el rango de la versión.',
                ]);
            }

            if ($yearTo !== null && $version->year_to !== null && (int) $yearTo > (int) $version->year_to) {
                throw ValidationException::withMessages([
                    "{$path}.year_to" => 'El año final no puede superar el rango de la versión.',
                ]);
            }

            if ($systemId && $version->multimediaSystems()->exists()) {
                $isRegistered = $version->multimediaSystems()
                    ->whereKey($systemId)
                    ->exists();

                if (! $isRegistered) {
                    throw ValidationException::withMessages([
                        "{$path}.vehicle_multimedia_system_id" => 'El sistema multimedia no está registrado para esta versión.',
                    ]);
                }
            }
        }
    }

    private function uniqueCompatibilities(array $compatibilities): Collection
    {
        return collect($compatibilities)->unique(fn (array $item) => implode('|', [
            $item['vehicle_brand_id'] ?? '',
            $item['vehicle_model_id'] ?? '',
            $item['vehicle_version_id'] ?? '',
            $item['vehicle_multimedia_system_id'] ?? '',
            $item['year_from'] ?? '',
            $item['year_to'] ?? '',
        ]))->values();
    }

    private function nullableTrim(mixed $value): ?string
    {
        $value = $value === null ? '' : trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
