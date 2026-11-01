<?php

use App\Models\Branch;
use App\Models\InventoryItem;
use App\Models\InventoryStock;
use App\Models\Product;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

// Isolated HTTP integration fixture. NOT a production entry point or seeder.
if (PHP_SAPI !== 'cli-server' || getenv('APP_ENV') !== 'testing' || getenv('DB_DATABASE') !== 'upgrade_test'
    || ! in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    http_response_code(404);
    exit;
}

$frontend = realpath((string) getenv('PUBLIC_CATALOG_FRONTEND_PATH'));
$qa = realpath((string) getenv('PUBLIC_CATALOG_QA_DIR'));
if (! $frontend || ! $qa || ! str_starts_with($qa, sys_get_temp_dir().'/upgrade79-dynamic-')) {
    http_response_code(503);
    exit;
}
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (! in_array($_SERVER['REQUEST_METHOD'], ['GET', 'HEAD'], true)) {
    http_response_code(405);
    exit;
}
if ($path === '/storage/products/qa-public-runtime.svg') {
    header('Content-Type: image/svg+xml');
    echo '<svg xmlns="http://www.w3.org/2000/svg" width="600" height="400"><rect width="600" height="400" fill="#101b2b"/><text x="50" y="200" fill="white">QA SINTETICO</text></svg>';

    return;
}
// Dynamic routes precede static files, exactly as the managed Apache block.
if (! preg_match('~^/tienda/[^/]+/?$~', $path) && $path !== '/sitemap.xml' && ! preg_match('~^/(api|sanctum)(/|$)~', $path)) {
    $file = realpath($frontend.$path);
    if ($file && str_starts_with($file, $frontend.'/') && is_file($file)) {
        return false;
    }
    $manifest = json_decode(@file_get_contents($frontend.'/seo/routes.json') ?: '{}', true);
    foreach ($manifest['pages'] ?? [] as $page) {
        if ($page['path'] === $path && ! str_starts_with($path, '/tienda/')) {
            header('Content-Type: text/html; charset=utf-8');
            readfile($frontend.'/'.$page['file']);

            return;
        }
    }
    $private = preg_match('~^/(crm|admin|login|carrito|checkout|orden-confirmada)(/|$)~', $path);
    http_response_code($private ? 200 : 404);
    header('Content-Type: text/html; charset=utf-8');
    readfile($frontend.'/seo/'.($private ? 'private.html' : '404.html'));

    return;
}

require __DIR__.'/../../vendor/autoload.php';
// Apache's internal rewrite executes laravel-api.php, not sitemap.xml. Emulate
// that SCRIPT_NAME while preserving the browser's original REQUEST_URI.
$_SERVER['SCRIPT_NAME'] = '/laravel-api.php';
$_SERVER['PHP_SELF'] = '/laravel-api.php';
$_SERVER['SCRIPT_FILENAME'] = $frontend.'/laravel-api.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();
if (! $app->environment('testing') || DB::selectOne('SELECT DATABASE() AS db')->db !== 'upgrade_test') {
    throw new RuntimeException('Unsafe QA database');
}
Mail::fake();
Queue::fake();
Http::preventStrayRequests();
config(['session.driver' => 'array', 'cache.default' => 'array', 'mail.default' => 'array', 'product_configurator.purchase_enabled' => false]);
config(['filesystems.disks.public.url' => 'http://'.$_SERVER['HTTP_HOST'].'/storage']);
DB::beginTransaction();
try {
    // Recreated in a transaction on every request, stable IDs for the browser.
    // Rollback ALWAYS: no products, stock, orders or QA rows remain afterwards.
    $idsFile = $qa.'/ids.json';
    $ids = is_file($idsFile) ? json_decode(file_get_contents($idsFile), true) : [
        'before' => Product::max('id') + 100,
        'after' => Product::max('id') + 101,
        'branch' => Branch::max('id') + 100,
    ];
    if (! is_file($idsFile)) {
        file_put_contents($idsFile, json_encode($ids));
    }
    if (Product::whereIn('id', [$ids['before'], $ids['after']])->exists() || Branch::whereKey($ids['branch'])->exists()) {
        throw new RuntimeException('Reserved QA identifiers already exist; do not overwrite them.');
    }
    $branch = Branch::forceCreate(['id' => $ids['branch'], 'name' => 'Runtime isolated QA', 'slug' => 'runtime-isolated-qa', 'code' => 'RT-QA', 'city' => 'Barranquilla', 'is_active' => true]);
    $phase = trim(@file_get_contents($qa.'/phase') ?: 'before');
    foreach ([$ids['before'] => 'qa-runtime-before', $ids['after'] => 'qa-runtime-after'] as $id => $slug) {
        if ($id === $ids['after'] && $phase === 'before') {
            continue;
        }
        $product = Product::forceCreate(['id' => $id, 'name' => 'QA '.$slug, 'slug' => $slug, 'sku' => strtoupper($slug), 'description' => 'QA sintético · no es mercancía real.',
            'price' => $phase === 'changed' ? 654321 : 123456, 'cost_price' => 54321, 'main_image' => 'products/qa-public-runtime.svg',
            'compatibility_type' => 'universal', 'is_visible' => $phase !== 'hidden', 'is_active' => true]);
        $item = InventoryItem::create(['product_id' => $product->id, 'product_variant_id' => null]);
        InventoryStock::create(['branch_id' => $branch->id, 'inventory_item_id' => $item->id, 'quantity' => 3, 'minimum_quantity' => 1]);
    }
    $request = Request::capture();
    $response = $kernel->handle($request);
    $response->send();
    $kernel->terminate($request, $response);
} finally {
    DB::rollBack();
}
