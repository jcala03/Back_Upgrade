<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $this->call(BranchSeeder::class);

        if (UserSeeder::shouldRun(
            app()->environment(),
            (bool) config('seeding.demo_users.enabled')
        )) {
            $this->call(UserSeeder::class);
        }
    }
}
