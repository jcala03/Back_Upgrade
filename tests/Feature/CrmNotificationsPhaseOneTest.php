<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\CrmNotification;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\InventoryStock;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Quotation;
use App\Models\User;
use App\Services\CrmNotificationService;
use App\Services\InventoryService;
use App\Services\PaymentService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Mockery\MockInterface;
use Tests\TestCase;

class CrmNotificationsPhaseOneTest extends TestCase
{
    use RefreshDatabase;

    public function createApplication()
    {
        $app = require __DIR__.'/../../bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
        if ($connection = getenv('TEST_DB_CONNECTION')) {
            $app['config']->set('database.default', $connection);
            $app['config']->set("database.connections.{$connection}.database", getenv('TEST_DB_DATABASE') ?: 'upgrade_notifications_test');
        }

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_personal_listing_permissions_filters_pagination_and_legacy_exclusion(): void
    {
        $admin = $this->user('admin');
        $sales = $this->user('user');
        $viewer = $this->user('viewer');
        $this->notification($admin, 'admin-read', now());
        $this->notification($admin, 'admin-unread');
        $this->notification($sales, 'sales-unread');
        CrmNotification::create(['type' => CrmNotification::TYPE_STOCK_LOW, 'severity' => 'warning', 'title' => 'Legacy', 'message' => 'Global legacy']);

        $this->getJson('/api/admin/notifications')->assertUnauthorized();
        Sanctum::actingAs($viewer);
        $this->getJson('/api/admin/notifications')->assertForbidden();
        $this->getJson('/api/admin/notifications/unread-count')->assertForbidden();

        Sanctum::actingAs($admin);
        $this->getJson('/api/admin/notifications?filter=unread&per_page=1')->assertOk()
            ->assertJsonPath('data.unread_count', 1)
            ->assertJsonPath('data.notifications.per_page', 1)
            ->assertJsonPath('data.notifications.total', 1)
            ->assertJsonPath('data.notifications.data.0.title', 'admin-unread')
            ->assertJsonMissingPath('data.notifications.data.0.user_id');
        $this->getJson('/api/admin/notifications/unread-count')->assertOk()->assertJsonPath('data.count', 1);

        Sanctum::actingAs($sales);
        $this->getJson('/api/admin/notifications')->assertOk()
            ->assertJsonPath('data.notifications.total', 1)
            ->assertJsonPath('data.notifications.data.0.title', 'sales-unread');
    }

    public function test_mark_one_is_idempotent_owned_and_mark_all_is_personal(): void
    {
        $admin = $this->user('admin');
        $sales = $this->user('user');
        $own = $this->notification($admin, 'Propia');
        $otherOwn = $this->notification($admin, 'Otra propia');
        $foreign = $this->notification($sales, 'Ajena');
        Sanctum::actingAs($admin);

        $this->postJson("/api/admin/notifications/{$own->id}/read")->assertOk();
        $firstRead = $own->fresh()->read_at;
        $this->postJson("/api/admin/notifications/{$own->id}/read")->assertOk();
        $this->assertTrue($firstRead->equalTo($own->fresh()->read_at));
        $this->postJson("/api/admin/notifications/{$foreign->id}/read")->assertNotFound();
        $this->postJson('/api/admin/notifications/read-all')->assertOk()->assertJsonPath('data.unread_count', 0);
        $this->assertNotNull($otherOwn->fresh()->read_at);
        $this->assertNull($foreign->fresh()->read_at);
    }

    public function test_stock_state_crossings_notify_product_and_variant_without_noise(): void
    {
        $admin = $this->user('admin');
        $sales = $this->user('user');
        $this->user('viewer');
        $inventory = app(InventoryService::class);
        $product = $this->product(stock: 4, minimum: 2);
        $branch = $this->branch();

        $inventory->moveAtBranch($branch, $product, InventoryMovement::TYPE_EXIT, 2, null);
        $this->assertSame(1, CrmNotification::where('type', CrmNotification::TYPE_STOCK_LOW)->count());
        $inventory->moveAtBranch($branch, $product, InventoryMovement::TYPE_EXIT, 1, null);
        $this->assertSame(1, CrmNotification::where('type', CrmNotification::TYPE_STOCK_LOW)->count());
        $inventory->moveAtBranch($branch, $product, InventoryMovement::TYPE_EXIT, 1, null);
        $this->assertSame(1, CrmNotification::where('type', CrmNotification::TYPE_STOCK_OUT)->count());
        $inventory->moveAtBranch($branch, $product, InventoryMovement::TYPE_ENTRY, 1, null);
        $inventory->moveAtBranch($branch, $product, InventoryMovement::TYPE_ENTRY, 2, null);
        $this->assertSame(2, CrmNotification::count());
        $inventory->moveAtBranch($branch, $product, InventoryMovement::TYPE_EXIT, 2, null);
        $this->assertSame(2, CrmNotification::where('type', CrmNotification::TYPE_STOCK_LOW)->count());

        $parent = $this->product(stock: 50, minimum: 1);
        $variant = ProductVariant::create([
            'product_id' => $parent->id, 'name' => '12.3 pulgadas', 'normalized_name' => '12.3 pulgadas',
            'sku' => 'VAR-123', 'price' => 100000,
            'is_active' => true, 'is_visible' => true,
        ]);
        $this->inventoryStock($parent, 3, 2, $variant);
        $inventory->moveAtBranch($branch, $parent, InventoryMovement::TYPE_EXIT, 1, null, variant: $variant);
        $variantNotification = CrmNotification::where('user_id', $admin->id)->where('reference_type', 'product_variant')->firstOrFail();
        $this->assertSame($variant->id, $variantNotification->data['product_variant_id']);
        $this->assertSame('VAR-123', $variantNotification->data['sku']);
        $this->assertDatabaseMissing('crm_notifications', ['user_id' => $sales->id, 'reference_id' => $variant->id]);
        $this->assertDatabaseMissing('crm_notifications', ['user_id' => $this->userByRole('viewer')?->id]);
    }

    public function test_ecommerce_order_and_completed_payment_producers_are_scoped_and_private(): void
    {
        $admin = $this->user('admin');
        $sales = $this->user('user');
        $product = $this->product(stock: 10, minimum: 1);
        $orderId = $this->withHeader('Idempotency-Key', __METHOD__)->postJson('/api/orders', [
            'customer_name' => 'Dato privado', 'customer_email' => 'private@example.com', 'customer_phone' => '3001234567',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertCreated()->json('data.id');
        $this->assertSame(1, CrmNotification::where('type', CrmNotification::TYPE_ORDER_ECOMMERCE_PENDING)->count());
        $notification = CrmNotification::where('user_id', $admin->id)->where('type', CrmNotification::TYPE_ORDER_ECOMMERCE_PENDING)->firstOrFail();
        $this->assertSame(['order_id' => $orderId, 'order_number' => Order::find($orderId)->order_number], $notification->data);
        $this->assertStringNotContainsString('private@example.com', $notification->message);

        Sanctum::actingAs($admin);
        $this->postJson('/api/admin/orders', [
            'branch_id' => $this->branch()->id,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertCreated();
        $this->assertSame(1, CrmNotification::where('type', CrmNotification::TYPE_ORDER_ECOMMERCE_PENDING)->count());
        $this->postJson("/api/admin/orders/{$orderId}/payments", ['amount' => 50000, 'method' => Payment::METHOD_CASH])->assertCreated();
        $this->assertSame(1, CrmNotification::where('type', CrmNotification::TYPE_PAYMENT_RECEIVED)->count());
        $paymentNotice = CrmNotification::where('user_id', $admin->id)->where('type', CrmNotification::TYPE_PAYMENT_RECEIVED)->firstOrFail();
        $this->assertSame(50000, $paymentNotice->data['amount']);
        $this->assertSame(Payment::METHOD_CASH, $paymentNotice->data['method']);

        $pendingPaymentOrder = Order::findOrFail($orderId);
        app(PaymentService::class)->register($pendingPaymentOrder, ['amount' => 1, 'method' => 'cash', 'status' => Payment::STATUS_PENDING]);
        app(PaymentService::class)->register($pendingPaymentOrder, ['amount' => 1, 'method' => 'cash', 'status' => Payment::STATUS_FAILED]);
        $this->assertSame(1, CrmNotification::where('type', CrmNotification::TYPE_PAYMENT_RECEIVED)->count());
    }

    public function test_quotation_conversion_notifies_only_after_success_and_dedupes(): void
    {
        $admin = $this->user('admin');
        $this->user('user');
        Sanctum::actingAs($admin);
        $product = $this->product(stock: 2, minimum: 0);
        $quotationId = $this->postJson('/api/admin/quotations', [
            'branch_id' => $this->branch()->id,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertCreated()->json('data.id');
        $this->postJson("/api/admin/quotations/{$quotationId}/convert")->assertCreated();
        $this->assertSame(1, CrmNotification::where('type', CrmNotification::TYPE_QUOTATION_CONVERTED)->count());
        $this->postJson("/api/admin/quotations/{$quotationId}/convert")->assertUnprocessable();
        $this->assertSame(1, CrmNotification::where('type', CrmNotification::TYPE_QUOTATION_CONVERTED)->count());
        $notice = CrmNotification::where('type', CrmNotification::TYPE_QUOTATION_CONVERTED)->firstOrFail();
        $this->assertSame($quotationId, $notice->data['quotation_id']);
        $this->assertNotNull($notice->data['order_id']);
    }

    public function test_expiring_command_is_timezone_aware_selective_and_idempotent(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-13 04:30:00', 'UTC'));
        $this->user('admin');
        $this->user('user');
        $tomorrow = '2026-08-13';
        $eligible = $this->quotation(Quotation::STATUS_DRAFT, $tomorrow);
        $this->quotation(Quotation::STATUS_SENT, $tomorrow);
        $this->quotation(Quotation::STATUS_DRAFT, '2026-08-12');
        $this->quotation(Quotation::STATUS_DRAFT, '2026-08-14');
        $this->quotation(Quotation::STATUS_REJECTED, $tomorrow);
        $this->quotation(Quotation::STATUS_EXPIRED, $tomorrow);
        $converted = $this->quotation(Quotation::STATUS_CONVERTED, $tomorrow);
        $converted->update(['order_id' => $this->rawOrder()->id]);

        $this->artisan('crm:notify-expiring-quotations')->assertSuccessful();
        $this->artisan('crm:notify-expiring-quotations')->assertSuccessful();
        $this->assertSame(2, CrmNotification::where('type', CrmNotification::TYPE_QUOTATION_EXPIRING)->count());
        $this->assertDatabaseHas('crm_notifications', ['reference_id' => $eligible->id, 'dedupe_key' => "quotation_expiring:{$eligible->id}:{$tomorrow}"]);

        $eligible->update(['valid_until' => '2026-08-14']);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-13 15:00:00', 'UTC'));
        $this->artisan('crm:notify-expiring-quotations')->assertSuccessful();
        $this->assertSame(4, CrmNotification::where('type', CrmNotification::TYPE_QUOTATION_EXPIRING)->count());
    }

    public function test_notification_failure_does_not_rollback_inventory_core(): void
    {
        $this->mock(CrmNotificationService::class, function (MockInterface $mock) {
            $mock->shouldReceive('distribute')->andThrow(new \RuntimeException('notification storage unavailable'));
        });
        $product = $this->product(stock: 3, minimum: 2);
        $stock = $this->inventoryStock($product, 3, 2);
        app(InventoryService::class)->moveAtBranch(
            $this->branch(),
            $product,
            InventoryMovement::TYPE_EXIT,
            1,
            null,
        );

        $this->assertSame(2, $stock->fresh()->quantity);
        $this->assertDatabaseHas('inventory_movements', [
            'branch_id' => $this->branch()->id,
            'inventory_item_id' => $stock->inventory_item_id,
            'product_id' => $product->id,
            'stock_after' => 2,
        ]);
    }

    private function user(string $role): User
    {
        return User::factory()->create(['role' => $role, 'is_active' => true]);
    }

    private function userByRole(string $role): ?User
    {
        return User::query()->where('role', $role)->first();
    }

    private function notification(User $user, string $title, $readAt = null): CrmNotification
    {
        return CrmNotification::create([
            'user_id' => $user->id, 'type' => CrmNotification::TYPE_STOCK_LOW,
            'severity' => CrmNotification::SEVERITY_WARNING, 'title' => $title,
            'message' => 'Mensaje', 'read_at' => $readAt,
        ]);
    }

    private function product(int $stock, int $minimum): Product
    {
        $product = Product::create([
            'name' => 'Producto '.uniqid(), 'slug' => 'producto-'.uniqid(), 'sku' => 'P-'.uniqid(),
            'price' => 100000, 'cost_price' => 40000,
            'is_active' => true, 'is_visible' => true,
        ]);

        $this->inventoryStock($product, $stock, $minimum);

        return $product;
    }

    private function branch(): Branch
    {
        return Branch::firstOrCreate(
            ['code' => 'NOTIF'],
            ['slug' => 'notificaciones', 'name' => 'Sede notificaciones', 'city' => 'Barranquilla', 'is_active' => true],
        );
    }

    private function inventoryStock(
        Product $product,
        int $quantity,
        int $minimum,
        ?ProductVariant $variant = null,
    ): InventoryStock {
        $item = InventoryItem::firstOrCreate($variant
            ? ['product_id' => null, 'product_variant_id' => $variant->id]
            : ['product_id' => $product->id, 'product_variant_id' => null]);

        return InventoryStock::updateOrCreate(
            ['branch_id' => $this->branch()->id, 'inventory_item_id' => $item->id],
            ['quantity' => $quantity, 'minimum_quantity' => $minimum],
        );
    }

    private function quotation(string $status, string $validUntil): Quotation
    {
        return Quotation::create([
            'quotation_number' => 'COT-'.uniqid(), 'status' => $status, 'valid_until' => $validUntil,
            'subtotal' => 100000, 'discount_total' => 0, 'total' => 100000,
        ]);
    }

    private function rawOrder(): Order
    {
        return Order::create([
            'order_number' => 'ORDER-'.uniqid(), 'origin' => Order::ORIGIN_CRM,
            'subtotal' => 100000, 'discount_total' => 0, 'total' => 100000,
            'status' => Order::STATUS_CONFIRMED, 'payment_status' => Order::PAYMENT_UNPAID,
        ]);
    }
}
