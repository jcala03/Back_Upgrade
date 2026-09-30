<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use LogicException;

class UserSeeder extends Seeder
{
    private const ALLOWED_ENVIRONMENTS = ['local', 'testing'];

    public function run(): void
    {
        if (! self::shouldRun(
            app()->environment(),
            (bool) config('seeding.demo_users.enabled')
        )) {
            return;
        }

        $users = collect(config('seeding.demo_users.accounts', []))
            ->filter(fn (array $user): bool => filled($user['email'] ?? null)
                || filled($user['password'] ?? null))
            ->values();

        if ($users->isEmpty()) {
            throw new LogicException(
                'Demo user seeding is enabled but no accounts are configured.'
            );
        }

        foreach ($users as $user) {
            $validator = Validator::make($user, [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email', 'max:255'],
                'password' => ['required', 'string', 'min:12'],
                'role' => ['required', 'in:'.implode(',', User::roles())],
            ]);

            if ($validator->fails()) {
                throw new LogicException(
                    'Demo user configuration is incomplete or invalid.'
                );
            }

            $validated = $validator->validated();

            User::updateOrCreate(
                ['email' => $validated['email']],
                [
                    'name' => $validated['name'],
                    'password' => Hash::make($validated['password']),
                    'role' => $validated['role'],
                    'is_active' => true,
                ]
            );
        }
    }

    public static function shouldRun(string $environment, bool $enabled): bool
    {
        return $enabled
            && in_array($environment, self::ALLOWED_ENVIRONMENTS, true);
    }
}
