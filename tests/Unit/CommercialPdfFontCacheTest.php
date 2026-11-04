<?php

namespace Tests\Unit;

use App\Services\CommercialDocumentPdf;
use App\Services\CommercialPdfFontCache;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Container\Container;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use PHPUnit\Framework\TestCase;

class CommercialPdfFontCacheTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workspace = sys_get_temp_dir().'/upgrade79-fontcache-test-'.bin2hex(random_bytes(12));
        mkdir($this->workspace, 0700);
        mkdir($this->workspace.'/temporary', 0700);
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->workspace);
        parent::tearDown();
    }

    public function test_warm_cache_contains_only_font_metadata_and_is_reused_privately(): void
    {
        $cache = new CommercialPdfFontCache($this->workspace.'/fonts');
        $directory = $cache->acquire(new Options, $this->workspace.'/temporary');
        $this->assertSame(0700, fileperms($directory) & 0777);
        foreach (['DejaVuSans.ufm.json', 'DejaVuSans-Bold.ufm.json'] as $font) {
            file_put_contents($directory.'/'.$font, json_encode(['_version_' => 6]));
        }
        $cache->release(true);
        $again = new CommercialPdfFontCache($this->workspace.'/fonts');
        $this->assertSame($directory, $again->acquire(new Options, $this->workspace.'/temporary'));
        $again->release(true);
        $this->assertFileExists($directory.'/.ready');
        $this->assertSame(0600, fileperms($directory.'/.ready') & 0777);
        $names = array_values(array_diff(scandir($directory), ['.', '..']));
        sort($names);
        $this->assertSame(['.ready', '.warmup.lock', 'DejaVuSans-Bold.ufm.json', 'DejaVuSans.ufm.json'], $names);
    }

    public function test_failed_render_releases_lock_without_marking_partial_metrics_as_ready(): void
    {
        $cache = new CommercialPdfFontCache($this->workspace.'/fonts');
        $directory = $cache->acquire(new Options, $this->workspace.'/temporary');
        file_put_contents($directory.'/DejaVuSans.ufm.json', '{incomplete');
        $cache->release(false);
        $this->assertFileDoesNotExist($directory.'/.ready');
        $lock = fopen($directory.'/.warmup.lock', 'c');
        $this->assertTrue(flock($lock, LOCK_EX | LOCK_NB));
        flock($lock, LOCK_UN);
        fclose($lock);
    }

    public function test_incomplete_or_invalid_metrics_never_become_a_ready_shared_cache(): void
    {
        foreach ([null, 'invalid-json', '{}'] as $contents) {
            $cache = new CommercialPdfFontCache($this->workspace.'/fonts');
            $directory = $cache->acquire(new Options, $this->workspace.'/temporary');
            if ($contents !== null) {
                file_put_contents($directory.'/DejaVuSans.ufm.json', $contents);
                file_put_contents($directory.'/DejaVuSans-Bold.ufm.json', $contents);
            }
            $cache->release(true);
            $this->assertFileDoesNotExist($directory.'/.ready');
        }
    }

    public function test_unavailable_storage_falls_back_to_the_original_private_workspace(): void
    {
        file_put_contents($this->workspace.'/blocked', 'not a directory');
        $cache = new CommercialPdfFontCache($this->workspace.'/blocked/fonts');
        $this->assertSame($this->workspace.'/temporary', $cache->acquire(new Options, $this->workspace.'/temporary'));
        $cache->release(true);
    }

    public function test_changed_bundled_font_metrics_get_a_new_namespace(): void
    {
        mkdir($this->workspace.'/source');
        foreach (['DejaVuSans.ufm', 'DejaVuSans-Bold.ufm'] as $font) {
            file_put_contents($this->workspace.'/source/'.$font, 'bundled metrics v1');
        }
        $options = new Options(['fontDir' => $this->workspace.'/source']);
        $cache = new CommercialPdfFontCache($this->workspace.'/fonts');
        $first = $cache->acquire($options, $this->workspace.'/temporary');
        $cache->release(false);
        file_put_contents($this->workspace.'/source/DejaVuSans.ufm', 'bundled metrics v2');
        $second = $cache->acquire($options, $this->workspace.'/temporary');
        $cache->release(false);
        $this->assertNotSame($first, $second);
    }

    public function test_renderer_failure_removes_private_workspace_and_releases_warmup_lock(): void
    {
        $previous = Container::getInstance();
        $application = new Application(dirname(__DIR__, 2));
        $application->useStoragePath($this->workspace.'/app-storage');
        $renderer = new class extends CommercialDocumentPdf
        {
            public ?string $temporary = null;

            protected function makeDompdf(Options $options): Dompdf
            {
                $this->temporary = $options->getTempDir();
                throw new \RuntimeException('Controlled renderer failure');
            }
        };
        try {
            try {
                $renderer->render((require __DIR__.'/../Fixtures/commercial-document-presentation.php')['quotation']);
                $this->fail('The rendering error must not be hidden.');
            } catch (\RuntimeException $exception) {
                $this->assertSame('Controlled renderer failure', $exception->getMessage());
            }
            $this->assertNotNull($renderer->temporary);
            $this->assertDirectoryDoesNotExist($renderer->temporary);
            $locks = glob($this->workspace.'/app-storage/framework/cache/commercial-pdf-fonts/*/.warmup.lock');
            $this->assertCount(1, $locks);
            $lock = fopen($locks[0], 'c');
            $this->assertTrue(flock($lock, LOCK_EX | LOCK_NB));
            flock($lock, LOCK_UN);
            fclose($lock);
        } finally {
            Container::setInstance($previous);
        }
    }
}
