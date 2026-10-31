<?php

use App\Models\Branch;
use App\Models\InventoryItem;
use App\Models\InventoryStock;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\VehicleBrand;
use App\Models\VehicleModel;
use App\Models\VehicleMultimediaSystem;
use App\Models\VehicleVersion;
use App\Services\PaymentService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}
require __DIR__.'/configurator-support.php';
[$app, $directory, $manifest] = configuratorQaApplication();
$action = $argv[1] ?? '';
if ($action === 'seed') {
    if ($manifest) {
        throw new RuntimeException('QA directory already seeded; no overwrite.');
    }
    $manifest = DB::transaction(function () {
        $run = 'qa-real-'.bin2hex(random_bytes(5));
        $image = 'http://127.0.0.1:5196/__qa-product.svg';
        $category = ProductCategory::create(['name' => $run, 'slug' => $run, 'is_active' => true]);
        $product = Product::create(['name' => 'Pantalla QA integral', 'slug' => $run.'-pantalla', 'category_id' => $category->id,
            'price' => 1200000, 'main_image' => $image, 'is_active' => true, 'is_visible' => true,
            'compatibility_type' => 'vehicle_specific', 'requires_shipping' => true]);
        $variant = $product->variants()->create(['name' => 'Referencia compartida QA', 'normalized_name' => 'referencia compartida qa',
            'sku' => strtoupper($run).'-SHARED', 'price' => 1200000, 'is_active' => true, 'is_visible' => true, 'is_default' => true]);
        foreach (['ram_gb' => 8, 'almacenamiento_gb' => 128] as $key => $value) {
            $field = $category->fields()->create(['name' => $key, 'field_key' => $key, 'type' => 'number', 'scope' => 'variant',
                'filter_unit' => 'GB', 'is_active' => true]);
            $variant->specValues()->create(['product_category_field_id' => $field->id, 'value_number' => $value]);
        }
        $brand = VehicleBrand::create(['name' => $run.' BMW', 'slug' => $run.'-bmw', 'is_active' => true]);
        $model = VehicleModel::create(['name' => 'Serie 3 QA', 'slug' => 'serie-3-qa', 'vehicle_brand_id' => $brand->id, 'is_active' => true]);
        $version = VehicleVersion::create(['name' => 'F30 QA', 'vehicle_model_id' => $model->id, 'year_from' => 2012, 'year_to' => 2018, 'is_active' => true]);
        $systems = [];
        foreach (['CIC', 'NBT'] as $name) {
            $system = VehicleMultimediaSystem::create(['name' => $name, 'slug' => strtolower($name), 'code' => strtolower($name), 'vehicle_brand_id' => $brand->id, 'is_active' => true]);
            $version->multimediaSystems()->attach($system->id);
            $variant->vehicleCompatibilities()->create(['vehicle_brand_id' => $brand->id, 'vehicle_model_id' => $model->id,
                'vehicle_version_id' => $version->id, 'vehicle_multimedia_system_id' => $system->id]);
            $systems[$name] = $system->id;
        }
        $branch = Branch::create(['name' => 'Sede QA integral', 'code' => substr($run, -10), 'slug' => $run.'-sede', 'city' => 'Barranquilla', 'is_active' => true]);
        $physical = InventoryItem::create(['product_variant_id' => $variant->id, 'product_id' => null]);
        $stock = InventoryStock::create(['branch_id' => $branch->id, 'inventory_item_id' => $physical->id, 'quantity' => 4]);
        $simple = Product::create(['name' => 'Producto simple QA', 'slug' => $run.'-simple', 'price' => 200000, 'main_image' => $image,
            'is_active' => true, 'is_visible' => true, 'compatibility_type' => 'universal', 'requires_shipping' => true]);
        $simpleItem = InventoryItem::create(['product_id' => $simple->id]);
        InventoryStock::create(['branch_id' => $branch->id, 'inventory_item_id' => $simpleItem->id, 'quantity' => 5]);
        $traditional = Product::create(['name' => 'Producto variante tradicional QA', 'slug' => $run.'-traditional', 'price' => 300000,
            'main_image' => $image, 'is_active' => true, 'is_visible' => true, 'compatibility_type' => 'universal', 'requires_shipping' => true]);
        $traditionalVariant = $traditional->variants()->create(['name' => 'Variante tradicional QA', 'normalized_name' => 'variante tradicional qa',
            'price' => 300000, 'is_active' => true, 'is_visible' => true, 'is_default' => true]);
        $traditionalItem = InventoryItem::create(['product_variant_id' => $traditionalVariant->id]);
        InventoryStock::create(['branch_id' => $branch->id, 'inventory_item_id' => $traditionalItem->id, 'quantity' => 5]);
        $admin = User::create(['name' => 'Administrador QA integral', 'email' => $run.'@example.test', 'password' => Hash::make('QA-only-not-a-real-account-2026'), 'role' => 'admin', 'is_active' => true]);

        return ['run' => $run, 'product_id' => $product->id, 'slug' => $product->slug, 'variant_id' => $variant->id, 'sku' => $variant->sku,
            'vehicle_brand_id' => $brand->id, 'vehicle_model_id' => $model->id, 'vehicle_version_id' => $version->id, 'systems' => $systems,
            'stock_id' => $stock->id, 'inventory_item_id' => $physical->id, 'branch_id' => $branch->id, 'branch_slug' => $branch->slug,
            'simple_id' => $simple->id, 'simple_slug' => $simple->slug, 'traditional_id' => $traditional->id, 'traditional_slug' => $traditional->slug,
            'traditional_variant_id' => $traditionalVariant->id, 'admin_id' => $admin->id, 'admin_email' => $admin->email];
    });
    // Runtime test artifact, never a repository configuration or commercial database.
    file_put_contents($directory.'/manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));
} elseif ($manifest) {
    $variant = ProductVariant::findOrFail($manifest['variant_id']);
    if ($action === 'compatibility-off' || $action === 'compatibility-on') {
        $variant->vehicleCompatibilities()->where('vehicle_multimedia_system_id', $action === 'compatibility-off' ? $manifest['systems']['CIC'] : $manifest['systems']['NBT'])
            ->where('id', $variant->vehicleCompatibilities()->min('id'))->update([
                'vehicle_multimedia_system_id' => $action === 'compatibility-off' ? $manifest['systems']['NBT'] : $manifest['systems']['CIC'],
            ]);
    } elseif ($action === 'catalog-off' || $action === 'catalog-on') {
        $variant->update(['is_visible' => $action === 'catalog-on']);
    } elseif ($action === 'stock-zero' || $action === 'stock-restore') {
        InventoryStock::findOrFail($manifest['stock_id'])->update(['quantity' => $action === 'stock-zero' ? 0 : 4]);
    } elseif ($action === 'price-change') {
        $variant->update(['price' => 1250000]);
    } elseif (in_array($action, ['decline', 'paid'], true)) {
        // A CLI-only test control, scoped to this run's buyer and product. Never an HTTP route.
        $order = Order::where('customer_email', $manifest['run'].'-buyer@example.test')
            ->whereHas('items', fn ($q) => $q->where('product_id', $manifest['product_id']))->sole();
        if (! $order->stock_committed_at || $order->status !== 'confirmed') {
            throw new RuntimeException('Cannot simulate payment before a real test reservation.');
        }
        $payment = $order->payments()->latest('id')->firstOrFail();
        $payment->update(['status' => $action === 'decline' ? Payment::STATUS_FAILED : Payment::STATUS_COMPLETED,
            'provider_status' => $action === 'decline' ? 'DECLINED' : 'APPROVED', 'paid_at' => $action === 'paid' ? now() : null]);
        app(PaymentService::class)->recalculate($order->fresh());
    } elseif ($action !== 'inspect') {
        throw new RuntimeException('Unknown QA action.');
    }
} else {
    throw new RuntimeException('Seed the isolated run first.');
}
$orders = Order::where('customer_email', 'like', $manifest['run'].'%')->with('items')->get();
echo json_encode(['database' => DB::selectOne('SELECT DATABASE() AS db')->db, 'manifest' => $manifest,
    'orders' => $orders->map(fn ($order) => ['id' => $order->id, 'number' => $order->order_number, 'status' => $order->status,
        'payment_status' => $order->payment_status, 'total' => $order->total,
        'items' => $order->items->map(fn ($item) => ['id' => $item->id, 'product_id' => $item->product_id, 'variant_id' => $item->product_variant_id,
            'quantity' => $item->quantity, 'unit_price' => $item->unit_price, 'configuration_context' => $item->configuration_context]),
        'payments' => $order->payments()->get(['id', 'status', 'provider_status', 'idempotency_key'])->toArray(),
    ]),
    'physical_inventory_items' => InventoryItem::where('product_variant_id', $manifest['variant_id'])->count(),
    'shared_stock' => InventoryStock::findOrFail($manifest['stock_id'])->quantity,
    'sale_movements' => DB::table('inventory_movements')->where('inventory_item_id', $manifest['inventory_item_id'])->where('type', 'sale')->count(),
], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
