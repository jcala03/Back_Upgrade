<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerVehicle;
use App\Models\Service;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class PersonalCommercialDiscoveryService
{
    public const RESULT_LIMIT = 20;

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function customers(string $search): Collection
    {
        $term = trim($search);
        $escaped = addcslashes($term, '\\%_');
        $like = "%{$escaped}%";
        $phone = preg_replace('/\D+/', '', $term);

        return Customer::query()
            ->select(['id', 'name', 'phone', 'email', 'document'])
            ->where('is_active', true)
            ->where(function (Builder $query) use ($like, $phone): void {
                $query->where('name', 'like', $like)
                    ->orWhere('phone', 'like', $like)
                    ->orWhere('email', 'like', $like)
                    ->orWhere('document', 'like', $like)
                    ->orWhereHas('vehicles', function (Builder $vehicles) use ($like): void {
                        $vehicles->where('is_active', true)->where('plate', 'like', $like);
                    });

                if ($phone !== '') {
                    $query->orWhere('phone_normalized', 'like', "%{$phone}%");
                }
            })
            ->with([
                'vehicles' => fn ($query) => $query
                    ->select([
                        'id', 'customer_id', 'vehicle_brand_id', 'vehicle_model_id',
                        'vehicle_version_id', 'year', 'plate', 'nickname',
                    ])
                    ->where('is_active', true)
                    ->latest('id')
                    ->limit(self::RESULT_LIMIT),
                'vehicles.vehicleBrand:id,name',
                'vehicles.vehicleModel:id,name',
                'vehicles.vehicleVersion:id,name,year_from,year_to',
            ])
            ->orderBy('name')
            ->orderBy('id')
            ->limit(self::RESULT_LIMIT)
            ->get()
            ->map(fn (Customer $customer): array => [
                'id' => $customer->id,
                'name' => $customer->name,
                'contact' => [
                    'phone' => $this->maskTail($customer->phone),
                    'email' => $this->maskEmail($customer->email),
                    'document' => $this->maskTail($customer->document),
                ],
                'vehicles' => $customer->vehicles
                    ->map(fn (CustomerVehicle $vehicle): array => $this->vehicle($vehicle))
                    ->values(),
            ]);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function services(?string $search): Collection
    {
        $term = trim((string) $search);
        $like = '%'.addcslashes($term, '\\%_').'%';

        return Service::query()
            ->select([
                'id', 'service_category_id', 'name', 'description', 'price',
                'estimated_duration_minutes', 'sort_order',
            ])
            ->commerciallyAvailable()
            ->with('category:id,name')
            ->when($term !== '', function (Builder $query) use ($like): void {
                $query->where(function (Builder $match) use ($like): void {
                    $match->where('name', 'like', $like)
                        ->orWhere('description', 'like', $like);
                });
            })
            ->orderBy('sort_order')
            ->orderBy('name')
            ->orderBy('id')
            ->limit(self::RESULT_LIMIT)
            ->get()
            ->map(fn (Service $service): array => [
                'id' => $service->id,
                'name' => $service->name,
                'description' => $service->description,
                'price' => $service->price,
                'estimated_duration_minutes' => $service->estimated_duration_minutes,
                'category' => $service->category ? [
                    'id' => $service->category->id,
                    'name' => $service->category->name,
                ] : null,
            ]);
    }

    /** @return array<string, mixed> */
    private function vehicle(CustomerVehicle $vehicle): array
    {
        $description = collect([
            $vehicle->vehicleBrand?->name,
            $vehicle->vehicleModel?->name,
            $vehicle->vehicleVersion?->name,
            $vehicle->year,
        ])->filter()->implode(' ');

        return [
            'id' => $vehicle->id,
            'display_name' => $vehicle->nickname ?: ($description ?: $vehicle->plate ?: 'Vehículo'),
            'nickname' => $vehicle->nickname,
            'brand' => $vehicle->vehicleBrand ? [
                'id' => $vehicle->vehicleBrand->id,
                'name' => $vehicle->vehicleBrand->name,
            ] : null,
            'model' => $vehicle->vehicleModel ? [
                'id' => $vehicle->vehicleModel->id,
                'name' => $vehicle->vehicleModel->name,
            ] : null,
            'version' => $vehicle->vehicleVersion ? [
                'id' => $vehicle->vehicleVersion->id,
                'name' => $vehicle->vehicleVersion->name,
            ] : null,
            'year' => $vehicle->year,
            'plate' => $vehicle->plate,
        ];
    }

    private function maskTail(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        $visible = mb_substr($value, -4);

        return str_repeat('•', max(3, mb_strlen($value) - mb_strlen($visible))).$visible;
    }

    private function maskEmail(?string $email): ?string
    {
        $email = trim((string) $email);
        if ($email === '' || ! str_contains($email, '@')) {
            return null;
        }

        [$local, $domain] = explode('@', $email, 2);

        return mb_substr($local, 0, 1).'•••@'.$domain;
    }
}
