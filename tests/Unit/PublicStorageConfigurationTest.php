<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class PublicStorageConfigurationTest extends TestCase
{
    #[DataProvider('environments')]
    public function test_public_disk_configuration_is_independent_of_http_request(string $appUrl, string $storageUrl, string $expected): void
    {
        $process = new Process([PHP_BINARY, '-r',
            'require "vendor/autoload.php"; new Illuminate\Foundation\Application(getcwd()); echo (require "config/filesystems.php")["disks"]["public"]["url"];',
        ], dirname(__DIR__, 2), ['APP_URL' => $appUrl, 'PUBLIC_STORAGE_URL' => $storageUrl]);
        $process->mustRun();
        $this->assertSame($expected, $process->getOutput());
    }

    public static function environments(): array
    {
        return [
            'local fallback' => ['http://127.0.0.1:8000/', '', 'http://127.0.0.1:8000/storage'],
            'same-origin production' => ['https://example.test', '', 'https://example.test/storage'],
            'independent public storage' => ['https://upgradecolombia.com', 'https://crmupgradecol.com/storage', 'https://crmupgradecol.com/storage'],
        ];
    }
}
