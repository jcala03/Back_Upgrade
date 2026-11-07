<?php

// Test-only stdio bridge, NOT a web endpoint/server. No ports, credentials or named DB.
use App\Models\Order;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\Support\MultimediaFixture;
use Tests\Support\MultimediaSqliteApplication;

if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--isolated-memory-qa') {
    exit(1);
}
require dirname(__DIR__, 2).'/vendor/autoload.php';
try {
    $app = MultimediaSqliteApplication::boot();
    MultimediaSqliteApplication::migrate($app);
    $profile = ($argv[2] ?? '') === 'reference' ? 'vehicle-oem-reference-v1' : 'vehicle-oem-memory-v1';
    $fixture = MultimediaFixture::create($profile, $profile === 'vehicle-oem-memory-v1' ? 'BMW' : 'Toyota');
    $kernel = $app->make(Kernel::class);
    echo json_encode(['ready' => true, 'driver' => 'sqlite', 'database' => ':memory:', 'slug' => $fixture['product']->slug,
        'context' => $fixture['context'], 'variant_id' => $fixture['variant']->id,
        'other_oem_id' => $fixture['oems'][1]->id]).PHP_EOL;
    while (($line = fgets(STDIN)) !== false) {
        $input = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
        MultimediaSqliteApplication::guard($app);
        if (($input['action'] ?? '') === 'inspect') {
            echo json_encode(['orders' => Order::count(), 'items' => DB::table('order_items')->get(),
                'stock' => $fixture['stock']->fresh()->quantity, 'movements' => DB::table('inventory_movements')->count(),
                'payments' => DB::table('payments')->count()]).PHP_EOL;

            continue;
        }
        if (($input['action'] ?? '') === 'block') {
            config(['product_configurator.purchase_enabled' => false]);
            echo json_encode(['blocked' => true]).PHP_EOL;

            continue;
        }
        $method = $input['method'] ?? 'GET';
        $path = $input['path'] ?? '';
        if (! ($method === 'GET' && preg_match('#^/api/(?:products(?:/|\?|$)|checkout/orders/)#', $path))
            && ! ($method === 'POST' && $path === '/api/orders')) {
            throw new RuntimeException('Request outside the isolated integration allowlist.');
        }
        $server = ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'];
        if (isset($input['key'])) {
            $server['HTTP_IDEMPOTENCY_KEY'] = $input['key'];
        }
        $request = Request::create($path, $method, [], [], [], $server,
            isset($input['body']) ? json_encode($input['body'], JSON_THROW_ON_ERROR) : null);
        $response = $kernel->handle($request);
        $result = ['status' => $response->getStatusCode(), 'body' => json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR)];
        $kernel->terminate($request, $response);
        echo json_encode($result, JSON_THROW_ON_ERROR).PHP_EOL;
    }
} catch (Throwable $exception) {
    fwrite(STDERR, get_class($exception).': '.$exception->getMessage().PHP_EOL);
    exit(1);
}
