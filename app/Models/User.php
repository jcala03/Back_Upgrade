<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password', 'role', 'is_active'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    public const ROLE_ADMIN = 'admin';

    public const ROLE_USER = 'user';

    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    public function isUser(): bool
    {
        return $this->role === self::ROLE_USER;
    }

    public static function roles(): array
    {
        return [self::ROLE_ADMIN, self::ROLE_USER];
    }

    public function permissions(): array
    {
        return match ($this->role) {
            self::ROLE_ADMIN => [
                'dashboard.view',
                'dashboard.financials',
                'business_overview.view',

                'products.view',
                'products.create',
                'products.update',
                'products.delete',

                'services.view',
                'services.create',
                'services.update',
                'services.delete',

                'inventory.view',
                'inventory.update',

                'inventory_transfers.view',
                'inventory_transfers.create',
                'inventory_transfers.dispatch',
                'inventory_transfers.receive',
                'inventory_transfers.cancel',

                'orders.view',
                'orders.create',
                'orders.update',
                'orders.cancel',

                'customers.view',
                'customers.create',
                'customers.update',

                'payments.view',
                'payments.create',
                'payments.reconcile',

                'quotations.view',
                'quotations.create',
                'quotations.update',
                'quotations.convert',

                'notifications.view',
                'notifications.update',

                'reports.view',
                'reports.financials',

                'settings.view',
                'settings.update',

                'users.view',
                'users.create',
                'users.update',
                'roles.manage',

                'employees.view',
                'employees.create',
                'employees.update',
                'employees.delete',

                'branches.view',
                'branches.create',
                'branches.update',

                'employee_schedules.view',
                'employee_schedules.update',

                'employee_leaves.view',
                'employee_leaves.create',
                'employee_leaves.update',
                'employee_leaves.cancel',

                'employee_availability.view',

                'tasks.view',
                'tasks.create',
                'tasks.update',
                'tasks.cancel',

                'appointments.view',
                'appointments.create',
                'appointments.update',
                'appointments.cancel',

                'calendar.view',

                'goals.view',
                'goals.create',
                'goals.update',
                'goals.cancel',

                'commissions.view',
            ],

            self::ROLE_USER => [
                'notifications.view',
                'notifications.update',
                'business_overview.view',
                ...$this->grantedCapabilities(),
            ],

            default => [],
        };
    }

    public function hasPermission(string $permission): bool
    {
        return in_array($permission, $this->permissions(), true);
    }

    public function capabilities(): HasMany
    {
        return $this->hasMany(UserCapability::class);
    }

    public function crmNotifications(): HasMany
    {
        return $this->hasMany(CrmNotification::class);
    }

    public function employee(): HasOne
    {
        return $this->hasOne(Employee::class);
    }

    private function grantedCapabilities(): array
    {
        if (! $this->exists) {
            return [];
        }

        if (! $this->relationLoaded('capabilities')) {
            $this->setRelation(
                'capabilities',
                $this->capabilities()->get(['id', 'user_id', 'capability'])
            );
        }

        $granted = $this->getRelation('capabilities')
            ->pluck('capability')
            ->flip();

        return array_values(array_filter(
            UserCapability::allowed(),
            fn (string $capability): bool => $granted->has($capability)
        ));
    }
}
