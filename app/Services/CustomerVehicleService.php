<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerVehicle;
use App\Models\User;
use App\Models\VehicleModel;
use App\Models\VehicleVersion;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CustomerVehicleService
{
    public function create(Customer $customer, array $data, User $user): CustomerVehicle
    {
        $values = $this->prepare($data);
        $this->validateVehicle($values);
        $this->validateVin($values['vin'] ?? null, (bool) ($values['is_active'] ?? true));

        return $customer->vehicles()->create($values + ['created_by' => $user->id, 'updated_by' => $user->id]);
    }

    public function update(CustomerVehicle $vehicle, array $data, User $user): CustomerVehicle
    {
        $values = $this->prepare($data);
        $candidate = [...$vehicle->getAttributes(), ...$values];
        $this->validateVehicle($candidate);
        $this->validateVin($candidate['vin'] ?? null, (bool) $candidate['is_active'], $vehicle);
        $vehicle->update($values + ['updated_by' => $user->id]);

        return $vehicle->fresh()->load(['vehicleBrand', 'vehicleModel', 'vehicleVersion']);
    }

    private function prepare(array $data): array
    {
        foreach (['plate', 'vin', 'color', 'nickname', 'notes'] as $field) {
            if (array_key_exists($field, $data) && is_string($data[$field])) {
                $data[$field] = trim($data[$field]) ?: null;
            }
        }
        if (array_key_exists('plate', $data) && $data['plate'] !== null) {
            $data['plate'] = Str::upper((string) preg_replace('/[^A-Za-z0-9]/', '', $data['plate']));
        }
        if (array_key_exists('vin', $data) && $data['vin'] !== null) {
            $data['vin'] = Str::upper((string) preg_replace('/\s+/', '', $data['vin']));
        }

        return $data;
    }

    private function validateVehicle(array $data): void
    {
        $brandId = isset($data['vehicle_brand_id']) ? (int) $data['vehicle_brand_id'] : null;
        $modelId = isset($data['vehicle_model_id']) ? (int) $data['vehicle_model_id'] : null;
        $versionId = isset($data['vehicle_version_id']) ? (int) $data['vehicle_version_id'] : null;
        $model = $modelId ? VehicleModel::find($modelId) : null;
        $version = $versionId ? VehicleVersion::find($versionId) : null;

        if ($model && (! $brandId || (int) $model->vehicle_brand_id !== $brandId)) {
            throw ValidationException::withMessages(['vehicle_model_id' => 'El modelo no pertenece a la marca indicada.']);
        }
        if ($version && (! $model || (int) $version->vehicle_model_id !== $model->id)) {
            throw ValidationException::withMessages(['vehicle_version_id' => 'La versión no pertenece al modelo indicado.']);
        }
        if ($version && isset($data['year'])) {
            $year = (int) $data['year'];
            if ($year < $version->year_from || ($version->year_to !== null && $year > $version->year_to)) {
                throw ValidationException::withMessages(['year' => 'El año no corresponde al rango de la versión indicada.']);
            }
        }
    }

    private function validateVin(?string $vin, bool $active, ?CustomerVehicle $ignore = null): void
    {
        if (! $vin || ! $active) {
            return;
        }
        $duplicate = CustomerVehicle::query()->where('vin', $vin)->where('is_active', true)
            ->when($ignore, fn ($query) => $query->where('id', '!=', $ignore->id))->exists();
        if ($duplicate) {
            throw ValidationException::withMessages(['vin' => 'Ya existe un vehículo activo con este VIN.']);
        }
    }
}
