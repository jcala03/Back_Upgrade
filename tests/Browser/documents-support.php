<?php

// Test-only bootstrap: never referenced by public/index.php or production routes.
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

function documentQaApplication(): array
{
    if (! in_array(PHP_SAPI, ['cli', 'cli-server'], true) || getenv('APP_ENV') !== 'testing'
        || getenv('DB_DATABASE') !== 'upgrade_test' || getenv('TEST_DB_DATABASE') !== 'upgrade_test'
        || getenv('DB_HOST') !== '127.0.0.1') {
        throw new RuntimeException('Document QA requires testing, loopback and upgrade_test.');
    }
    $directory = realpath(getenv('DOCUMENTS_QA_DIR') ?: '');
    if (! $directory || ! str_starts_with($directory, '/tmp/upgrade79-documents-browser-')) {
        throw new RuntimeException('Invalid isolated QA workspace.');
    }
    require_once __DIR__.'/../../vendor/autoload.php';
    $app = require __DIR__.'/../../bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    $row = DB::selectOne('SELECT DATABASE() AS db, @@basedir AS basedir');
    if (! $app->environment('testing') || config('database.default') !== 'mysql'
        || DB::connection()->getConfig('host') !== '127.0.0.1'
        || $row->db !== 'upgrade_test' || rtrim($row->basedir, '/') !== '/opt/lampp') {
        throw new RuntimeException('Effective database guard failed.');
    }
    config([
        'app.key' => getenv('DOCUMENTS_QA_KEY'),
        'app.url' => 'http://127.0.0.1:8198', 'frontend.url' => 'http://127.0.0.1:5198',
        'session.driver' => 'file', 'session.files' => $directory.'/sessions',
        'session.cookie' => 'upgrade79_documents_qa', 'session.domain' => null,
        'session.secure' => false, 'session.lottery' => [0, 100],
        'cache.default' => 'array', 'mail.default' => 'array', 'commercial_documents.real_mail_enabled' => false,
        'queue.default' => 'sync', 'sanctum.stateful' => ['127.0.0.1:5198', '127.0.0.1:8198'],
        'cors.allowed_origins' => ['http://127.0.0.1:5198'],
    ]);
    Http::preventStrayRequests();
    Queue::fake();
    $manifest = is_file($directory.'/manifest.json') ? json_decode(file_get_contents($directory.'/manifest.json'), true, flags: JSON_THROW_ON_ERROR) : null;

    return [$app, $directory, $manifest];
}
