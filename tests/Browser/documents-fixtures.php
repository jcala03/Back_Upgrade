<?php

use App\Models\Branch;
use App\Models\Employee;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Quotation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}
require __DIR__.'/documents-support.php';
[$app, $directory, $manifest] = documentQaApplication();
if (($argv[1] ?? '') === 'seed') {
    if ($manifest) {
        throw new RuntimeException('No duplicate fixtures or overwrites.');
    }
    $manifest = DB::transaction(function () {
        $tag = 'qa-doc-'.bin2hex(random_bytes(5));
        $branch = Branch::create(['name' => 'Sede documentos QA', 'code' => substr($tag, -10), 'slug' => $tag, 'city' => 'Barranquilla', 'is_active' => true]);
        $users = [];
        foreach (['admin', 'user'] as $role) {
            $users[$role] = User::create(['name' => 'Documento QA '.$role, 'email' => $tag.'-'.$role.'@example.test',
                'password' => Hash::make('QA-only-documents-not-a-real-account'), 'role' => $role, 'is_active' => true]);
        }
        $employee = Employee::create(['user_id' => $users['user']->id, 'branch_id' => $branch->id, 'name' => 'Asesor documentos QA', 'job_title' => 'Asesor QA', 'is_active' => true]);
        $common = ['customer_name' => 'Cliente PDF de prueba', 'customer_email' => 'cliente-qa@example.test', 'customer_phone' => '3001234567',
            'branch_id' => $branch->id, 'sales_employee_id' => $employee->id, 'subtotal' => 1200000, 'discount_total' => 20000, 'total' => 1180000];
        $quote = Quotation::create($common + ['quotation_number' => 'COT-'.$tag, 'status' => 'draft', 'valid_until' => now()->addDays(15)]);
        $line = ['item_type' => 'product', 'product_name' => 'Pantalla QA sintética para PDF', 'variant_name' => 'Referencia QA 8/128',
            'variant_sku' => strtoupper($tag), 'variant_specs' => ['ram_gb' => 8, 'almacenamiento_gb' => 128],
            'unit_price' => 1200000, 'quantity' => 1, 'subtotal' => 1200000, 'discount_amount' => 20000, 'total' => 1180000];
        $quote->items()->create($line);
        $orders = [];
        foreach (['pending', 'paid'] as $mode) {
            $order = Order::create($common + ['order_number' => strtoupper($mode).'-'.$tag, 'origin' => 'crm',
                'status' => $mode === 'paid' ? 'completed' : 'pending', 'payment_status' => $mode === 'paid' ? 'paid' : 'unpaid']);
            $order->items()->create($line + ['configuration_context' => ['vehicle_brand_name' => 'BMW QA', 'vehicle_model_name' => 'Serie 3 QA',
                'vehicle_version_name' => 'F30 QA', 'vehicle_multimedia_system_name' => 'CIC QA', 'ram_gb' => 8, 'almacenamiento_gb' => 128]]);
            if ($mode === 'paid') {
                Payment::create(['order_id' => $order->id, 'amount' => 1180000, 'status' => 'completed', 'method' => 'cash',
                    'reference' => 'QA aislado: sin pago real', 'paid_at' => now()]);
            }
            $orders[$mode] = ['id' => $order->id, 'number' => $order->order_number];
        }

        return ['tag' => $tag, 'admin_email' => $users['admin']->email, 'user_email' => $users['user']->email,
            'quote_id' => $quote->id, 'quote_number' => $quote->quotation_number, 'orders' => $orders];
    });
    file_put_contents($directory.'/manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));
} elseif (($argv[1] ?? '') !== 'inspect' || ! $manifest) {
    throw new RuntimeException('Unknown action or missing fixture.');
}
$quotation = Quotation::whereKey($manifest['quote_id'])->where('quotation_number', $manifest['quote_number'])->sole();
$orders = Order::whereIn('id', array_column($manifest['orders'], 'id'))->get();
if ($orders->count() !== 2 || $orders->contains(fn ($order) => ! str_ends_with($order->order_number, $manifest['tag']))) {
    throw new RuntimeException('Fixture identity mismatch.');
}
echo json_encode(['database' => DB::selectOne('SELECT DATABASE() AS db')->db, 'manifest' => $manifest,
    'snapshot' => ['quote' => $quotation->getAttributes(), 'orders' => $orders->map->getAttributes()->all(),
        'items' => DB::table('order_items')->whereIn('order_id', $orders->pluck('id'))->get()->all(),
        'stock_rows' => DB::table('inventory_stocks')->count(), 'movements' => DB::table('inventory_movements')->count()]],
    JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT).PHP_EOL;
