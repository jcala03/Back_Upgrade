<?php

namespace Tests\Support;

use App\Models\User;

final class CommercialAccess
{
    public const DEFAULT_PERMISSIONS = [
        'notifications.view', 'notifications.update', 'business_overview.view',
        'inventory.view_own',
        'quotations.view_own', 'quotations.create_own', 'quotations.update_own',
        'quotations.send_own', 'quotations.convert_own',
        'orders.view_own', 'orders.create_own', 'orders.confirm_own',
        'orders.complete_own', 'payments.create_own',
    ];

    /** Preserve negative authorization coverage independently of role defaults. */
    public static function limited(User $user, array $permissions): User
    {
        $limited = new class extends User
        {
            protected $table = 'users';

            public array $testPermissions = [];

            public function permissions(): array
            {
                return $this->testPermissions;
            }
        };
        $limited->setRawAttributes($user->getAttributes(), true);
        $limited->exists = true;
        $limited->testPermissions = $permissions;

        return $limited;
    }
}
