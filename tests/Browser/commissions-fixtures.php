<?php

// Isolated browser fixtures only; no route, seed command or production bootstrap references this file.
use App\Models\Branch;
use App\Models\Employee;
use App\Models\EmployeeCommission;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\User;
use App\Services\CommissionService;
use App\Services\OrderService;
use App\Services\PaymentService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}
require __DIR__.'/documents-support.php';
[$app, $directory, $manifest] = documentQaApplication();
$fingerprint = static function (array $limits): array {
    $hashes = [];
    foreach ($limits as $table => $id) {
        $hashes[$table] = hash('sha256', DB::table($table)->where('id', '<=', $id)->orderBy('id')->get()->toJson());
    }

    return $hashes;
};
if (($argv[1] ?? '') === 'seed') {
    if ($manifest) {
        throw new RuntimeException('Cannot replace an existing QA manifest.');
    }
    $limits = [];
    foreach (['orders', 'order_items', 'payments', 'employee_commissions', 'products', 'inventory_stocks', 'inventory_movements'] as $table) {
        $limits[$table] = (int) DB::table($table)->max('id');
    }
    $before = $fingerprint($limits);
    $manifest = DB::transaction(function () use ($limits, $before) {
        $tag = 'qa-comm-'.bin2hex(random_bytes(5));
        $users = [];
        foreach (['admin', 'user', 'other'] as $role) {
            $users[$role] = User::create(['name' => 'Comisiones QA '.$role, 'email' => $tag.'-'.$role.'@example.test',
                'password' => Hash::make('QA-only-commissions-not-a-real-account'), 'role' => $role === 'admin' ? 'admin' : 'user', 'is_active' => true]);
        }
        $branches = [];
        $employees = [];
        foreach (['user', 'other'] as $role) {
            $branchTag = $tag.'-'.$role;
            $branches[$role] = Branch::create(['name' => 'Sede comisiones QA '.$role, 'code' => substr(hash('sha256', $branchTag), 0, 10),
                'slug' => $branchTag, 'city' => 'Barranquilla', 'is_active' => true]);
            $employees[$role] = Employee::create(['user_id' => $users[$role]->id, 'branch_id' => $branches[$role]->id,
                'name' => 'Vendedor comisiones QA '.$role, 'job_title' => 'Asesor QA', 'is_active' => true]);
        }
        $product = Product::create(['name' => 'Referencia sintética comisiones QA', 'slug' => $tag, 'sku' => strtoupper($tag),
            'price' => 100000, 'commission_enabled' => true, 'commission_amount' => 14000, 'is_active' => true, 'is_visible' => false]);
        $orders = [];
        foreach (['earned', 'pending', 'refund', 'other'] as $mode) {
            $role = $mode === 'other' ? 'other' : 'user';
            $order = Order::create(['order_number' => strtoupper($mode.'-'.$tag), 'branch_id' => $branches[$role]->id,
                'sales_employee_id' => $employees[$role]->id, 'created_by' => $users['admin']->id, 'origin' => 'crm',
                'status' => 'confirmed', 'payment_status' => 'unpaid', 'confirmed_at' => now(), 'currency' => 'COP',
                'customer_name' => 'Cliente sintético QA', 'subtotal' => 100000, 'discount_total' => 0, 'total' => 100000]);
            $order->items()->create(['item_type' => 'product', 'product_id' => $product->id, 'product_name' => $product->name,
                'product_sku' => $product->sku, 'quantity' => 1, 'unit_price' => 100000, 'subtotal' => 100000, 'discount_amount' => 0, 'total' => 100000]);
            $commission = app(CommissionService::class)->createPendingForOrder($order)->sole();
            $payment = app(PaymentService::class)->register($order, ['amount' => $mode === 'pending' ? 40000 : 100000,
                'method' => 'cash', 'reference' => 'QA: no representa pago real'], $users['admin']);
            app(OrderService::class)->transition($order, 'completed', $users['admin']);
            if ($mode === 'refund') {
                // Fixture-only refund. No gateway calls or production refund flow changes.
                $payment->update(['status' => 'refunded']);
                $order->update(['payment_status' => 'refunded']);
            }
            $orders[$mode] = ['id' => $order->id, 'number' => $order->order_number, 'commission_id' => $commission->id];
        }

        return ['tag' => $tag, 'admin_email' => $users['admin']->email, 'user_email' => $users['user']->email,
            'employee_id' => $employees['user']->id, 'branch_id' => $branches['user']->id, 'other_branch_id' => $branches['other']->id,
            'orders' => $orders, 'limits' => $limits, 'original_hashes' => $before];
    });
    file_put_contents($directory.'/manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));
} elseif (($argv[1] ?? '') !== 'inspect' || ! $manifest) {
    throw new RuntimeException('Unknown action or missing fixture.');
}
if ($fingerprint($manifest['limits']) !== $manifest['original_hashes']) {
    throw new RuntimeException('Persistent records predating QA have changed.');
}
$orderIds = array_column($manifest['orders'], 'id');
echo json_encode(['database' => DB::selectOne('SELECT DATABASE() AS db')->db, 'manifest' => $manifest, 'original_records_unchanged' => true,
    'snapshot' => ['orders' => Order::whereIn('id', $orderIds)->orderBy('id')->get()->map->getAttributes()->all(),
        'commissions' => EmployeeCommission::whereIn('order_id', $orderIds)->orderBy('id')->get()->map->getAttributes()->all(),
        'payments' => Payment::whereIn('order_id', $orderIds)->orderBy('id')->get()->map->getAttributes()->all()]], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT).PHP_EOL;
