<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => '***REMOVED***'],
            [
                'name' => 'Admin UP GRADE 79',
                'password' => Hash::make('***REMOVED***'),
                'role' => User::ROLE_ADMIN,
                'is_active' => true,
            ]
        );

        User::updateOrCreate(
            ['email' => '***REMOVED***'],
            [
                'name' => 'Ventas UP GRADE 79',
                'password' => Hash::make('***REMOVED***'),
                'role' => User::ROLE_USER,
                'is_active' => true,
            ]
        );
    }
}
