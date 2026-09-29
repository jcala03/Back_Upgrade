<?php

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        $app = parent::createApplication();

        $this->guardTestingDatabase($app);

        return $app;
    }

    private function guardTestingDatabase(Application $app): void
    {
        $expected = 'upgrade_test';
        $connection = $app['config']->get('database.default');
        $database = $app['config']->get('database.connections.mysql.database');
        $testConnection = $this->environmentValue('TEST_DB_CONNECTION');
        $testDatabase = $this->environmentValue('TEST_DB_DATABASE');

        if (
            $app->environment() !== 'testing'
            || $connection !== 'mysql'
            || $database !== $expected
            || $testConnection !== 'mysql'
            || $testDatabase !== $expected
        ) {
            throw new RuntimeException(
                'Unsafe test database configuration. Expected APP_ENV=testing, '
                .'DB_CONNECTION/TEST_DB_CONNECTION=mysql and '
                .'DB_DATABASE/TEST_DB_DATABASE=upgrade_test.'
            );
        }

        $row = $app->make('db')
            ->connection('mysql')
            ->selectOne('SELECT DATABASE() AS name');

        if (($row->name ?? null) !== $expected) {
            throw new RuntimeException(
                'Unsafe effective test database. SELECT DATABASE() must return upgrade_test.'
            );
        }
    }

    private function environmentValue(string $key): ?string
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

        return is_string($value) ? $value : null;
    }
}
