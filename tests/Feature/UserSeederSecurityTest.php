<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class UserSeederSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_production_never_seeds_demo_users_even_when_enabled(): void
    {
        config()->set('seeding.demo_users', $this->demoConfiguration());
        $originalEnvironment = $this->app->environment();
        $this->app['env'] = 'production';

        try {
            (new DatabaseSeeder)
                ->setContainer($this->app)
                ->__invoke();
        } finally {
            $this->app['env'] = $originalEnvironment;
        }

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('branches', 2);
    }

    public function test_testing_can_seed_explicit_demo_users_idempotently(): void
    {
        $configuration = $this->demoConfiguration();
        config()->set('seeding.demo_users', $configuration);

        (new DatabaseSeeder)->setContainer($this->app)->__invoke();
        (new DatabaseSeeder)->setContainer($this->app)->__invoke();

        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseCount('branches', 2);

        foreach ($configuration['accounts'] as $account) {
            $user = User::where('email', $account['email'])->firstOrFail();

            $this->assertTrue(Hash::check($account['password'], $user->password));
            $this->assertSame($account['role'], $user->role);
        }
    }

    public function test_local_and_testing_are_the_only_allowed_environments(): void
    {
        $this->assertTrue(UserSeeder::shouldRun('local', true));
        $this->assertTrue(UserSeeder::shouldRun('testing', true));
        $this->assertFalse(UserSeeder::shouldRun('production', true));
        $this->assertFalse(UserSeeder::shouldRun('staging', true));
        $this->assertFalse(UserSeeder::shouldRun('local', false));
    }

    private function demoConfiguration(): array
    {
        return [
            'enabled' => true,
            'accounts' => [
                [
                    'name' => 'QA administrator',
                    'email' => 'qa-administrator@example.test',
                    'password' => Str::random(32),
                    'role' => User::ROLE_ADMIN,
                ],
                [
                    'name' => 'QA operator',
                    'email' => 'qa-operator@example.test',
                    'password' => Str::random(32),
                    'role' => User::ROLE_USER,
                ],
            ],
        ];
    }
}
