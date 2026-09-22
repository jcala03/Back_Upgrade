<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class CustomerService
{
    public function search(?string $search, int $perPage): LengthAwarePaginator
    {
        $term = trim((string) $search);
        $phone = $this->normalizePhone($term);

        return Customer::query()
            ->withCount(['vehicles', 'orders'])
            ->when($term !== '', function (Builder $query) use ($term, $phone) {
                $query->where(function (Builder $match) use ($term, $phone) {
                    $like = "%{$term}%";
                    $match->where('name', 'like', $like)
                        ->orWhere('phone', 'like', $like)
                        ->orWhere('email', 'like', '%'.Str::lower($term).'%')
                        ->orWhere('document', 'like', $like);
                    if ($phone !== null) {
                        $match->orWhere('phone_normalized', 'like', "%{$phone}%");
                    }
                });
            })
            ->latest()
            ->paginate($perPage)
            ->withQueryString();
    }

    public function create(array $data, User $user): Customer
    {
        return Customer::create($this->normalize($data) + ['created_by' => $user->id, 'updated_by' => $user->id]);
    }

    public function update(Customer $customer, array $data, User $user): Customer
    {
        $customer->update($this->normalize($data) + ['updated_by' => $user->id]);

        return $customer->fresh();
    }

    public function detail(Customer $customer): Customer
    {
        $customer->loadCount(['vehicles', 'orders']);
        $customer->load([
            'vehicles' => fn ($query) => $query->where('is_active', true)
                ->with(['vehicleBrand', 'vehicleModel', 'vehicleVersion'])
                ->latest(),
        ]);
        $customer->setRelation('recentOrders', $customer->orders()->latest()->limit(10)->get());

        return $customer;
    }

    private function normalize(array $data): array
    {
        foreach (['name', 'phone', 'email', 'document', 'city', 'address', 'notes'] as $field) {
            if (array_key_exists($field, $data) && is_string($data[$field])) {
                $data[$field] = trim($data[$field]) ?: null;
            }
        }
        if (isset($data['email'])) {
            $data['email'] = Str::lower($data['email']);
        }
        if (array_key_exists('phone', $data)) {
            $data['phone_normalized'] = $this->normalizePhone($data['phone']);
        }

        return $data;
    }

    private function normalizePhone(?string $phone): ?string
    {
        $normalized = preg_replace('/\D+/', '', (string) $phone);

        return $normalized !== '' ? $normalized : null;
    }
}
