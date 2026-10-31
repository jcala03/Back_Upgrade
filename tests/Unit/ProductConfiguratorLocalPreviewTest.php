<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/** Configuration-only subprocesses: no application boot, database or fixture writes. */
class ProductConfiguratorLocalPreviewTest extends TestCase
{
    private function configuration(array $overrides = []): array
    {
        $process = new Process([PHP_BINARY, '-r',
            'require "vendor/autoload.php"; echo json_encode(require "config/product_configurator.php");',
        ], dirname(__DIR__, 2), array_merge([
            'APP_ENV' => 'local', 'DB_CONNECTION' => 'mysql', 'DB_HOST' => '127.0.0.1',
            'DB_PORT' => '3306', 'DB_DATABASE' => 'upgrade', 'DB_URL' => '',
        ], $overrides));
        $process->mustRun();

        return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_only_reviewed_local_product_gets_preview_without_buying(): void
    {
        $config = $this->configuration();
        $this->assertTrue($config['preview_enabled']);
        $this->assertFalse($config['purchase_enabled']);
        $this->assertSame(['environment' => 'local', 'driver' => 'mysql', 'host' => '127.0.0.1', 'port' => '3306', 'database' => 'upgrade'], $config['catalog']);
        $this->assertSame([6 => ['profile_id' => 'vehicle-oem-memory-v1', 'profile_version' => 1]], $config['products']);
    }

    #[DataProvider('otherTargets')]
    public function test_other_environments_and_catalogs_keep_empty_disabled_defaults(array $target): void
    {
        $config = $this->configuration($target);
        $this->assertFalse($config['preview_enabled']);
        $this->assertFalse($config['purchase_enabled']);
        $this->assertNull($config['catalog']);
        $this->assertSame([], $config['products']);
    }

    public static function otherTargets(): array
    {
        return [
            'production' => [['APP_ENV' => 'production']],
            'testing' => [['APP_ENV' => 'testing']],
            'test database' => [['DB_DATABASE' => 'upgrade_test']],
            'other database' => [['DB_DATABASE' => 'another_catalog']],
            'remote host' => [['DB_HOST' => 'db.example.test']],
            'other port' => [['DB_PORT' => '3307']],
            'other driver' => [['DB_CONNECTION' => 'sqlite']],
            'URL override' => [['DB_URL' => 'mysql://example.test/another_catalog']],
        ];
    }
}
