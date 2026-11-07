<?php

namespace Tests\Support;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/** Dedicated opt-in harness. Never loads .env, cached config, or a named database. */
final class MultimediaSqliteApplication
{
    public static function boot(): Application
    {
        if (! extension_loaded('pdo_sqlite')) {
            throw new RuntimeException('Isolated QA requires pdo_sqlite; no MySQL fallback is allowed.');
        }
        foreach (['APP_ENV' => 'testing', 'APP_CONFIG_CACHE' => '/__multimedia_no_config_cache__',
            'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:', 'DB_URL' => '',
            'PRODUCT_CONFIGURATOR_REGISTRATION' => '', 'PRODUCT_CONFIGURATOR_PREVIEW_ENABLED' => 'false',
            'PRODUCT_CONFIGURATOR_PURCHASE_ENABLED' => 'false', 'PRODUCT_CONFIGURATOR_PRODUCTION_APPROVED' => 'false'] as $key => $value) {
            putenv($key.'='.$value);
            $_ENV[$key] = $_SERVER[$key] = $value;
        }
        $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
        $app->useEnvironmentPath('/__multimedia_no_environment_files__');
        $app->afterBootstrapping(LoadConfiguration::class, function (Application $app): void {
            $app['config']->set([
                'app.env' => 'testing', 'app.key' => 'base64:'.base64_encode(str_repeat('q', 32)),
                'database.default' => 'sqlite',
                'database.connections' => ['sqlite' => ['driver' => 'sqlite', 'database' => ':memory:',
                    'url' => null, 'prefix' => '', 'foreign_key_constraints' => true]],
                'cache.default' => 'array', 'session.driver' => 'array', 'queue.default' => 'sync',
                'mail.default' => 'array', 'logging.default' => 'null',
                'services.webpush.enabled' => false,
                'services.wompi' => [], 'services.envia' => [], 'services.dhl' => [],
            ]);
        });
        $app->make(Kernel::class)->bootstrap();
        self::guard($app);
        DB::connection()->getPdo()->sqliteCreateCollation('utf8mb4_bin', strcmp(...));
        Http::preventStrayRequests();
        Mail::fake();
        Queue::fake();

        return $app;
    }

    public static function guard(Application $app): void
    {
        if (! $app->environment('testing') || config('database.default') !== 'sqlite'
            || array_keys(config('database.connections')) !== ['sqlite']
            || DB::connection()->getDatabaseName() !== ':memory:'
            || DB::connection()->getPdo()->getAttribute(\PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
            throw new RuntimeException('Unsafe isolated multimedia QA connection.');
        }
        foreach (DB::select('PRAGMA database_list') as $database) {
            if ($database->file !== '') {
                throw new RuntimeException('A file-backed database is forbidden in isolated multimedia QA.');
            }
        }
    }

    public static function migrate(Application $app): void
    {
        self::guard($app);
        $builder = DB::connection()->getSchemaBuilder();
        $app->instance('db.schema', $builder);
        $builder->blueprintResolver(fn ($connection, $table, $callback) => new class($connection, $table, $callback) extends Blueprint
        {
            public function dropForeign($index)
            {
                // Translate the two named MySQL FKs to SQLite's column-addressed API.
                $columns = ['inventory_movements_product_id_foreign' => ['product_id'],
                    'inventory_movements_product_variant_id_foreign' => ['product_variant_id']];

                return parent::dropForeign(is_string($index) ? ($columns[$index] ?? $index) : $index);
            }
        });
        foreach (glob(database_path('migrations/*.php')) as $path) {
            $name = basename($path);
            if ($name === '2026_08_20_000001_replace_sales_role_with_user.php') {
                // No historical users exist; apply the same final default below.
                continue;
            }
            if ($name === '2026_09_17_193255_drop_legacy_stock_columns_from_products_and_product_variants.php') {
                // SQLite requires dropping the legacy stock index explicitly first.
                Schema::table('products', fn (Blueprint $table) => $table->dropIndex('products_stock_index'));
            }
            if (in_array($name, [
                '2026_08_25_000004_create_inventory_items_table.php',
                '2026_08_25_000006_create_inventory_transfers_table.php',
                '2026_08_25_000007_create_inventory_transfer_items_table.php',
            ], true)) {
                // SQLite supports the same CHECKs only inside CREATE TABLE, not ALTER ADD.
                $queries = DB::pretend(fn () => (require $path)->up());
                $checks = [];
                foreach ($queries as $query) {
                    if (preg_match('/^\s*ALTER TABLE (\w+)\s+ADD CONSTRAINT (\w+)\s+CHECK\s*(\(.*\))\s*$/si', $query['query'], $match)) {
                        $checks[] = 'CONSTRAINT '.$match[2].' CHECK '.$match[3];
                    }
                }
                if ($checks === []) {
                    throw new RuntimeException('Expected explicit CHECK constraints in '.$name);
                }
                foreach ($queries as $query) {
                    $sql = $query['query'];
                    if (preg_match('/^\s*ALTER TABLE/i', $sql)) {
                        if (! preg_match('/^\s*ALTER TABLE (\w+)\s+ADD CONSTRAINT (\w+)\s+CHECK\s*(\(.*\))\s*$/si', $sql)) {
                            throw new RuntimeException('Unexpected nonportable DDL in '.$name);
                        }

                        continue;
                    }
                    if (str_starts_with($sql, 'create table')) {
                        $sql = substr($sql, 0, -1).', '.implode(', ', $checks).')';
                    }
                    DB::statement($sql, $query['bindings']);
                }

                continue;
            }
            (require $path)->up();
        }
        Schema::table('users', fn (Blueprint $table) => $table->string('role')->default('user')->change());
        self::guard($app);
    }
}
