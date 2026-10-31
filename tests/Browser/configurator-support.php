<?php

// Test-only bootstrap. Never loaded by public/index.php, providers or production routes.
use App\Models\Product;
use App\Services\ProductConfiguratorRegistry;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

function configuratorQaApplication(): array
{
    if (! in_array(PHP_SAPI, ['cli', 'cli-server'], true)
        || getenv('APP_ENV') !== 'testing' || getenv('DB_DATABASE') !== 'upgrade_test'
        || getenv('TEST_DB_DATABASE') !== 'upgrade_test'
        || getenv('DB_HOST') !== '127.0.0.1') {
        throw new RuntimeException('QA is restricted to CLI/testing/loopback/upgrade_test.');
    }
    $directory = realpath(getenv('CONFIGURATOR_QA_DIR') ?: '');
    if (! $directory || ! str_starts_with($directory, '/tmp/upgrade79-configurator-real-')) {
        throw new RuntimeException('Missing isolated QA directory.');
    }
    require_once __DIR__.'/../../vendor/autoload.php';
    $app = require __DIR__.'/../../bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    $connection = DB::connection();
    $server = $connection->selectOne('SELECT DATABASE() AS db, @@basedir AS basedir');
    if (! $app->environment('testing') || config('database.default') !== 'mysql'
        || $connection->getConfig('host') !== '127.0.0.1'
        || $connection->getDatabaseName() !== 'upgrade_test' || $server->db !== 'upgrade_test'
        || rtrim($server->basedir, '/') !== '/opt/lampp'
        || ! Schema::hasColumn('order_items', 'configuration_context')) {
        throw new RuntimeException('Effective QA database guard failed. No writes authorized.');
    }
    config([
        'app.url' => 'http://127.0.0.1:8196',
        'app.key' => getenv('CONFIGURATOR_QA_APP_KEY'),
        'session.driver' => 'file', 'session.files' => $directory.'/sessions',
        'session.cookie' => 'upgrade79_configurator_qa', 'session.domain' => null,
        'session.secure' => false, 'session.lottery' => [0, 100],
        'cache.default' => 'array', 'mail.default' => 'array', 'queue.default' => 'sync',
        'sanctum.stateful' => ['127.0.0.1:5196', '127.0.0.1:8196'],
        'cors.allowed_origins' => ['http://127.0.0.1:5196'],
        'product_configurator.products' => [], 'product_configurator.catalog' => null,
        'product_configurator.preview_enabled' => false, 'product_configurator.purchase_enabled' => false,
        // Fake signing parameters only. No real provider credential or transaction is used.
        'services.wompi' => [
            'base_url' => 'https://sandbox.wompi.co/v1', 'checkout_url' => 'https://checkout.wompi.co/p/',
            'public_key' => 'pub_test_configurator_qa', 'private_key' => 'prv_test_configurator_qa',
            'integrity_secret' => 'test_integrity_configurator_qa', 'events_secret' => 'test_events_configurator_qa',
            'redirect_url' => 'http://127.0.0.1:5196/checkout/payment/return', 'reservation_minutes' => 30,
        ],
    ]);
    Mail::fake();
    Queue::fake();
    Http::preventStrayRequests();
    Http::fake(function () {
        throw new RuntimeException('External HTTP forbidden in configurator QA.');
    });
    $path = $directory.'/manifest.json';
    $manifest = is_file($path) ? json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR) : null;
    if ($manifest !== null) {
        $product = Product::findOrFail($manifest['product_id']);
        if ($product->slug !== $manifest['slug'] || ! str_starts_with($product->slug, 'qa-real-')) {
            throw new RuntimeException('Fixture identity mismatch.');
        }
        config([
            'product_configurator.catalog' => app(ProductConfiguratorRegistry::class)->catalogIdentity($product),
            'product_configurator.products' => [$product->id => ['profile_id' => 'vehicle-oem-memory-v1', 'profile_version' => 1]],
            'product_configurator.preview_enabled' => true, 'product_configurator.purchase_enabled' => true,
        ]);
    }

    return [$app, $directory, $manifest];
}
