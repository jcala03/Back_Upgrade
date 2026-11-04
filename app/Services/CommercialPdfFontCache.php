<?php

namespace App\Services;

use Composer\InstalledVersions;
use Dompdf\Options;

/** Only public, bundled font metrics. Never document text, subsets or PDF bytes. */
final class CommercialPdfFontCache
{
    private mixed $lock = null;

    private ?string $directory = null;

    public function __construct(private readonly string $root) {}

    public function acquire(Options $options, string $fallback): string
    {
        $fingerprint = InstalledVersions::getVersion('dompdf/dompdf');
        foreach (['DejaVuSans.ufm', 'DejaVuSans-Bold.ufm'] as $font) {
            $path = $options->getFontDir().'/'.$font;
            if (! is_readable($path)) {
                return $fallback;
            }
            $fingerprint .= hash_file('sha256', $path);
        }
        $directory = $this->root.'/'.hash('sha256', $fingerprint);
        if ((! is_dir($directory) && ! @mkdir($directory, 0700, true)) || ! is_writable($directory)) {
            return $fallback;
        }
        if (is_file($directory.'/.ready')) {
            return $directory;
        }
        $lock = @fopen($directory.'/.warmup.lock', 'c');
        if ($lock === false) {
            return $fallback;
        }
        @chmod($directory.'/.warmup.lock', 0600);
        if (! flock($lock, LOCK_EX)) {
            fclose($lock);

            return $fallback;
        }
        // Another request may have finished warming while this one waited.
        if (is_file($directory.'/.ready')) {
            flock($lock, LOCK_UN);
            fclose($lock);

            return $directory;
        }
        $this->directory = $directory;
        $this->lock = $lock;

        return $directory;
    }

    public function release(bool $rendered): void
    {
        if (! is_resource($this->lock)) {
            return;
        }
        try {
            if ($rendered) {
                foreach (['DejaVuSans.ufm.json', 'DejaVuSans-Bold.ufm.json'] as $font) {
                    $path = $this->directory.'/'.$font;
                    $metrics = is_readable($path) ? json_decode(file_get_contents($path), true) : null;
                    if (! is_array($metrics) || ! isset($metrics['_version_'])) {
                        return;
                    }
                }
                @file_put_contents($this->directory.'/.ready', 'bundled-font-metrics', LOCK_EX);
                @chmod($this->directory.'/.ready', 0600);
            }
        } finally {
            flock($this->lock, LOCK_UN);
            fclose($this->lock);
            $this->lock = null;
        }
    }
}
