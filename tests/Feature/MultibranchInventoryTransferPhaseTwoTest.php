<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\InventoryStock;
use App\Models\InventoryTransfer;
use App\Models\InventoryTransferItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

class MultibranchInventoryTransferPhaseTwoTest extends TestCase
{
    use RefreshDatabase;

    public function createApplication()
    {
        $connection = trim((string) getenv('TEST_DB_CONNECTION'));
        $database = trim((string) getenv('TEST_DB_DATABASE'));

        if ($connection !== 'mysql' || $database === '') {
            throw new RuntimeException('MultibranchInventoryTransferPhaseTwoTest requiere TEST_DB_CONNECTION=mysql y TEST_DB_DATABASE explícita.');
        }

        if (strcasecmp($database, 'upgrade') === 0) {
            throw new RuntimeException('MultibranchInventoryTransferPhaseTwoTest no puede ejecutarse contra la base principal upgrade.');
        }

        $app = require __DIR__.'/../../bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
        $app['config']->set('database.default', $connection);
        $app['config']->set("database.connections.{$connection}.database", $database);

        if (strcasecmp((string) $app['config']->get("database.connections.{$connection}.database"), 'upgrade') === 0) {
            throw new RuntimeException('La base configurada para MultibranchInventoryTransferPhaseTwoTest no puede ser upgrade.');
        }

        return $app;
    }

    public function test_transfer_endpoints_require_authentication(): void
    {
        $this->getJson('/api/admin/inventory/transfers')->assertUnauthorized();
        $this->postJson('/api/admin/inventory/transfers', [])->assertUnauthorized();
        $this->getJson('/api/admin/inventory/transfers/1')->assertUnauthorized();
        $this->postJson('/api/admin/inventory/transfers/1/dispatch')->assertUnauthorized();
        $this->postJson('/api/admin/inventory/transfers/1/receive')->assertUnauthorized();
        $this->postJson('/api/admin/inventory/transfers/1/cancel', ['reason' => 'No autorizada'])->assertUnauthorized();
    }

    public function test_base_user_has_no_transfer_permissions_and_cannot_access_endpoints(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'is_active' => true]);
        [$source, $destination] = $this->branches();
        $product = $this->product();
        $item = InventoryItem::create(['product_id' => $product->id]);
        $transfer = InventoryTransfer::create([
            'number' => 'TRF-20260823-9999',
            'source_branch_id' => $source->id,
            'destination_branch_id' => $destination->id,
            'status' => InventoryTransfer::STATUS_REQUESTED,
            'requested_by' => $admin->id,
            'requested_at' => now(),
        ]);
        $transfer->items()->create([
            'inventory_item_id' => $item->id,
            'product_id' => $product->id,
            'product_name_snapshot' => $product->name,
            'sku_snapshot' => $product->sku,
            'quantity' => 1,
        ]);
        $user = User::factory()->create(['role' => User::ROLE_USER, 'is_active' => true]);
        Sanctum::actingAs($user);

        foreach (['view', 'create', 'dispatch', 'receive', 'cancel'] as $action) {
            $this->assertFalse($user->hasPermission("inventory_transfers.{$action}"));
        }

        $this->getJson('/api/admin/inventory/transfers')->assertForbidden();
        $this->postJson('/api/admin/inventory/transfers', [])->assertForbidden();
        $this->getJson("/api/admin/inventory/transfers/{$transfer->id}")->assertForbidden();
        $this->postJson("/api/admin/inventory/transfers/{$transfer->id}/dispatch")->assertForbidden();
        $this->postJson("/api/admin/inventory/transfers/{$transfer->id}/receive")->assertForbidden();
        $this->postJson("/api/admin/inventory/transfers/{$transfer->id}/cancel", ['reason' => 'No autorizada'])->assertForbidden();
    }

    public function test_admin_has_all_transfer_permissions(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'is_active' => true]);

        foreach (['view', 'create', 'dispatch', 'receive', 'cancel'] as $action) {
            $this->assertTrue($admin->hasPermission("inventory_transfers.{$action}"));
        }
    }

    public function test_transfer_business_timestamps_are_not_automatically_mutable_in_mariadb(): void
    {
        $columns = collect(DB::select(<<<'SQL'
            SELECT COLUMN_NAME, DATA_TYPE, IS_NULLABLE, EXTRA
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'inventory_transfers'
              AND COLUMN_NAME IN ('requested_at', 'dispatched_at', 'received_at', 'cancelled_at')
            SQL))->keyBy('COLUMN_NAME');

        $this->assertCount(4, $columns);
        $this->assertSame('datetime', strtolower((string) $columns['requested_at']->DATA_TYPE));
        $this->assertSame('NO', $columns['requested_at']->IS_NULLABLE);

        foreach ($columns as $column) {
            $this->assertSame('datetime', strtolower((string) $column->DATA_TYPE));
            $this->assertStringNotContainsString('on update', strtolower((string) $column->EXTRA));
        }
    }

    public function test_multibranch_composite_indexes_are_reused_by_foreign_keys_without_redundant_singles(): void
    {
        $indexColumns = function (string $table): array {
            return collect(DB::select(<<<'SQL'
                SELECT INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS indexed_columns
                FROM information_schema.STATISTICS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = ?
                GROUP BY INDEX_NAME
                SQL, [$table]))
                ->mapWithKeys(fn ($index) => [$index->INDEX_NAME => $index->indexed_columns])
                ->all();
        };

        $transfers = $indexColumns('inventory_transfers');
        $items = $indexColumns('inventory_transfer_items');
        $movements = $indexColumns('inventory_movements');

        $this->assertContains('source_branch_id,requested_at', $transfers);
        $this->assertContains('destination_branch_id,requested_at', $transfers);
        $this->assertNotContains('source_branch_id', $transfers);
        $this->assertNotContains('destination_branch_id', $transfers);

        $this->assertContains('inventory_transfer_id,inventory_item_id', $items);
        $this->assertNotContains('inventory_transfer_id', $items);

        $this->assertContains('branch_id,created_at', $movements);
        $this->assertContains('inventory_item_id,created_at', $movements);
        $this->assertContains('inventory_transfer_item_id,type', $movements);
        $this->assertNotContains('branch_id', $movements);
        $this->assertNotContains('inventory_item_id', $movements);
        $this->assertNotContains('inventory_transfer_item_id', $movements);
    }

    public function test_admin_creates_requested_transfer_with_server_number_snapshots_and_no_stock_reservation(): void
    {
        $admin = $this->admin();
        [$source, $destination] = $this->branches();
        $product = $this->product(name: 'Pantalla BMW', sku: 'BMW-123');

        $response = $this->postJson('/api/admin/inventory/transfers', $this->transferPayload($source, $destination, [
            ['product_id' => $product->id, 'quantity' => 3],
        ], notes: 'Enviar con cuidado'));

        $response->assertCreated()
            ->assertJsonPath('data.status', InventoryTransfer::STATUS_REQUESTED)
            ->assertJsonPath('data.source_branch.id', $source->id)
            ->assertJsonPath('data.destination_branch.id', $destination->id)
            ->assertJsonPath('data.items.0.product_name_snapshot', 'Pantalla BMW')
            ->assertJsonPath('data.items.0.variant_name_snapshot', null)
            ->assertJsonPath('data.items.0.sku_snapshot', 'BMW-123')
            ->assertJsonPath('data.items.0.quantity', 3)
            ->assertJsonPath('data.requester.id', $admin->id)
            ->assertJsonMissingPath('data.requester.email')
            ->assertJsonMissingPath('data.requester.permissions');

        $number = (string) $response->json('data.number');
        $this->assertMatchesRegularExpression('/^TRF-\d{8}-\d{4,}$/', $number);
        $this->assertDatabaseHas('inventory_transfers', [
            'number' => $number,
            'status' => InventoryTransfer::STATUS_REQUESTED,
            'source_branch_id' => $source->id,
            'destination_branch_id' => $destination->id,
            'requested_by' => $admin->id,
            'notes' => 'Enviar con cuidado',
        ]);
        $this->assertDatabaseHas('inventory_transfer_items', [
            'product_id' => $product->id,
            'product_variant_id' => null,
            'product_name_snapshot' => 'Pantalla BMW',
            'sku_snapshot' => 'BMW-123',
            'quantity' => 3,
        ]);
        $this->assertDatabaseCount('inventory_stocks', 0);
        $this->assertDatabaseCount('inventory_movements', 0);
    }

    public function test_variant_transfer_preserves_product_variant_and_sku_snapshots(): void
    {
        $this->admin();
        [$source, $destination] = $this->branches();
        $product = $this->product(name: 'Pantalla Android', sku: 'PARENT');
        $variant = $this->variant($product, '12.3 pulgadas', 'ANDROID-123');

        $this->postJson('/api/admin/inventory/transfers', $this->transferPayload($source, $destination, [[
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'quantity' => 2,
        ]]))->assertCreated()
            ->assertJsonPath('data.items.0.product_id', $product->id)
            ->assertJsonPath('data.items.0.product_variant_id', $variant->id)
            ->assertJsonPath('data.items.0.product_name_snapshot', $product->name)
            ->assertJsonPath('data.items.0.variant_name_snapshot', $variant->display_name)
            ->assertJsonPath('data.items.0.sku_snapshot', 'ANDROID-123');

        $this->assertDatabaseHas('inventory_transfer_items', [
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'product_name_snapshot' => $product->name,
            'variant_name_snapshot' => $variant->display_name,
            'sku_snapshot' => 'ANDROID-123',
        ]);
    }

    public function test_transfer_creation_validates_branches_items_quantities_duplicates_and_variant_ownership(): void
    {
        $this->admin();
        [$source, $destination] = $this->branches();
        $product = $this->product();
        $otherProduct = $this->product();
        $variant = $this->variant($otherProduct, 'Ajena');

        $this->postJson('/api/admin/inventory/transfers', $this->transferPayload($source, $source, [
            ['product_id' => $product->id, 'quantity' => 1],
        ]))->assertUnprocessable()->assertJsonValidationErrors('destination_branch_id');

        $source->update(['is_active' => false]);
        $this->postJson('/api/admin/inventory/transfers', $this->transferPayload($source, $destination, [
            ['product_id' => $product->id, 'quantity' => 1],
        ]))->assertUnprocessable()->assertJsonValidationErrors('source_branch_id');
        $source->update(['is_active' => true]);

        $destination->update(['is_active' => false]);
        $this->postJson('/api/admin/inventory/transfers', $this->transferPayload($source, $destination, [
            ['product_id' => $product->id, 'quantity' => 1],
        ]))->assertUnprocessable()->assertJsonValidationErrors('destination_branch_id');
        $destination->update(['is_active' => true]);

        $this->postJson('/api/admin/inventory/transfers', $this->transferPayload($source, $destination, [
            ['product_id' => $product->id, 'quantity' => 0],
        ]))->assertUnprocessable()->assertJsonValidationErrors('items.0.quantity');

        $this->postJson('/api/admin/inventory/transfers', $this->transferPayload($source, $destination, [
            ['product_id' => $product->id, 'quantity' => 1],
            ['product_id' => $product->id, 'quantity' => 2],
        ]))->assertUnprocessable()->assertJsonValidationErrors('items.1');

        $this->postJson('/api/admin/inventory/transfers', $this->transferPayload($source, $destination, [[
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'quantity' => 1,
        ]]))->assertUnprocessable()->assertJsonValidationErrors('items.0.product_variant_id');

        $this->assertDatabaseCount('inventory_transfers', 0);
        $this->assertDatabaseCount('inventory_transfer_items', 0);
    }

    public function test_requested_transfer_does_not_require_current_source_stock(): void
    {
        $this->admin();
        [$source, $destination] = $this->branches();
        $product = $this->product();

        $this->postJson('/api/admin/inventory/transfers', $this->transferPayload($source, $destination, [
            ['product_id' => $product->id, 'quantity' => 100],
        ]))->assertCreated()->assertJsonPath('data.status', InventoryTransfer::STATUS_REQUESTED);

        $this->assertDatabaseCount('inventory_stocks', 0);
        $this->assertDatabaseCount('inventory_movements', 0);
    }

    public function test_client_request_key_is_idempotent_and_rejects_a_different_replay(): void
    {
        $this->admin();
        [$source, $destination] = $this->branches();
        $product = $this->product();
        $payload = $this->transferPayload($source, $destination, [
            ['product_id' => $product->id, 'quantity' => 2],
        ], clientRequestKey: 'transfer-retry-001');

        $first = $this->postJson('/api/admin/inventory/transfers', $payload)->assertCreated();
        $second = $this->postJson('/api/admin/inventory/transfers', $payload)->assertOk();

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertDatabaseCount('inventory_transfers', 1);
        $this->assertDatabaseCount('inventory_transfer_items', 1);

        $payload['items'][0]['quantity'] = 3;
        $this->postJson('/api/admin/inventory/transfers', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('client_request_key');
        $this->assertDatabaseCount('inventory_transfers', 1);
    }

    public function test_dispatch_atomically_decrements_only_source_and_links_transfer_out_history(): void
    {
        $admin = $this->admin();
        [$source, $destination] = $this->branches();
        $product = $this->product();
        $this->seedStock($source, $product, 8);
        $transferId = $this->requestTransfer($source, $destination, $product, 3);
        $transferItem = InventoryTransferItem::where('inventory_transfer_id', $transferId)->firstOrFail();

        $this->postJson("/api/admin/inventory/transfers/{$transferId}/dispatch")
            ->assertOk()
            ->assertJsonPath('data.status', InventoryTransfer::STATUS_IN_TRANSIT)
            ->assertJsonPath('data.dispatcher.id', $admin->id);

        $item = InventoryItem::where('product_id', $product->id)->firstOrFail();
        $this->assertDatabaseHas('inventory_stocks', [
            'branch_id' => $source->id,
            'inventory_item_id' => $item->id,
            'quantity' => 5,
        ]);
        $this->assertDatabaseMissing('inventory_stocks', [
            'branch_id' => $destination->id,
            'inventory_item_id' => $item->id,
        ]);
        $this->assertDatabaseHas('inventory_movements', [
            'branch_id' => $source->id,
            'inventory_item_id' => $item->id,
            'inventory_transfer_item_id' => $transferItem->id,
            'type' => InventoryMovement::TYPE_TRANSFER_OUT,
            'quantity_delta' => -3,
            'stock_before' => 8,
            'stock_after' => 5,
        ]);
        $this->assertNotNull(InventoryTransfer::findOrFail($transferId)->dispatched_at);
    }

    public function test_duplicate_dispatch_is_idempotent_and_does_not_move_stock_twice(): void
    {
        $this->admin();
        [$source, $destination] = $this->branches();
        $product = $this->product();
        $this->seedStock($source, $product, 5);
        $transferId = $this->requestTransfer($source, $destination, $product, 2);

        $this->postJson("/api/admin/inventory/transfers/{$transferId}/dispatch")->assertOk();
        $this->postJson("/api/admin/inventory/transfers/{$transferId}/dispatch")
            ->assertOk()
            ->assertJsonPath('data.status', InventoryTransfer::STATUS_IN_TRANSIT);

        $this->assertSame(3, $this->stockQuantity($source, $product));
        $this->assertSame(1, InventoryMovement::where('type', InventoryMovement::TYPE_TRANSFER_OUT)->count());
    }

    public function test_dispatch_with_one_insufficient_item_rolls_back_all_items_and_status(): void
    {
        $this->admin();
        [$source, $destination] = $this->branches();
        $first = $this->product(name: 'Primero');
        $second = $this->product(name: 'Segundo');
        $this->seedStock($source, $first, 5);
        $this->seedStock($source, $second, 1);
        $transferId = $this->postJson('/api/admin/inventory/transfers', $this->transferPayload($source, $destination, [
            ['product_id' => $first->id, 'quantity' => 2],
            ['product_id' => $second->id, 'quantity' => 2],
        ]))->assertCreated()->json('data.id');
        $movementCount = InventoryMovement::count();

        $this->postJson("/api/admin/inventory/transfers/{$transferId}/dispatch")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('quantity');

        $this->assertSame(5, $this->stockQuantity($source, $first));
        $this->assertSame(1, $this->stockQuantity($source, $second));
        $this->assertSame($movementCount, InventoryMovement::count());
        $this->assertDatabaseHas('inventory_transfers', [
            'id' => $transferId,
            'status' => InventoryTransfer::STATUS_REQUESTED,
            'dispatched_by' => null,
            'dispatched_at' => null,
        ]);
    }

    public function test_dispatch_revalidates_active_source_branch(): void
    {
        $this->admin();
        [$source, $destination] = $this->branches();
        $product = $this->product();
        $this->seedStock($source, $product, 3);
        $transferId = $this->requestTransfer($source, $destination, $product, 1);
        $source->update(['is_active' => false]);

        $this->postJson("/api/admin/inventory/transfers/{$transferId}/dispatch")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('source_branch_id');

        $this->assertSame(3, $this->stockQuantity($source, $product));
        $this->assertDatabaseHas('inventory_transfers', ['id' => $transferId, 'status' => InventoryTransfer::STATUS_REQUESTED]);
    }

    public function test_receive_moves_in_transit_quantity_to_destination_once_and_links_history(): void
    {
        $admin = $this->admin();
        [$source, $destination] = $this->branches();
        $product = $this->product();
        $this->seedStock($source, $product, 6);
        $transferId = $this->requestTransfer($source, $destination, $product, 4);
        $this->postJson("/api/admin/inventory/transfers/{$transferId}/dispatch")->assertOk();
        $transferItem = InventoryTransferItem::where('inventory_transfer_id', $transferId)->firstOrFail();

        $this->postJson("/api/admin/inventory/transfers/{$transferId}/receive")
            ->assertOk()
            ->assertJsonPath('data.status', InventoryTransfer::STATUS_RECEIVED)
            ->assertJsonPath('data.receiver.id', $admin->id);
        $this->postJson("/api/admin/inventory/transfers/{$transferId}/receive")
            ->assertOk()
            ->assertJsonPath('data.status', InventoryTransfer::STATUS_RECEIVED);

        $this->assertSame(2, $this->stockQuantity($source, $product));
        $this->assertSame(4, $this->stockQuantity($destination, $product));
        $this->assertSame(1, InventoryMovement::where('type', InventoryMovement::TYPE_TRANSFER_IN)->count());
        $this->assertDatabaseHas('inventory_movements', [
            'branch_id' => $destination->id,
            'inventory_transfer_item_id' => $transferItem->id,
            'type' => InventoryMovement::TYPE_TRANSFER_IN,
            'quantity_delta' => 4,
            'stock_before' => 0,
            'stock_after' => 4,
        ]);
        $this->assertNotNull(InventoryTransfer::findOrFail($transferId)->received_at);
    }

    public function test_receive_requires_in_transit_and_revalidates_active_destination(): void
    {
        $this->admin();
        [$source, $destination] = $this->branches();
        $product = $this->product();
        $this->seedStock($source, $product, 3);
        $transferId = $this->requestTransfer($source, $destination, $product, 1);

        $this->postJson("/api/admin/inventory/transfers/{$transferId}/receive")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        $this->postJson("/api/admin/inventory/transfers/{$transferId}/dispatch")->assertOk();
        $destination->update(['is_active' => false]);
        $this->postJson("/api/admin/inventory/transfers/{$transferId}/receive")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('destination_branch_id');

        $this->assertDatabaseHas('inventory_transfers', ['id' => $transferId, 'status' => InventoryTransfer::STATUS_IN_TRANSIT]);
        $this->assertDatabaseMissing('inventory_stocks', ['branch_id' => $destination->id]);
    }

    public function test_cancel_requested_is_idempotent_for_same_reason_and_never_moves_stock(): void
    {
        $admin = $this->admin();
        [$source, $destination] = $this->branches();
        $product = $this->product();
        $transferId = $this->requestTransfer($source, $destination, $product, 2);

        $this->postJson("/api/admin/inventory/transfers/{$transferId}/cancel", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reason');

        $this->postJson("/api/admin/inventory/transfers/{$transferId}/cancel", [
            'reason' => 'Solicitud duplicada',
        ])->assertOk()
            ->assertJsonPath('data.status', InventoryTransfer::STATUS_CANCELLED)
            ->assertJsonPath('data.cancellation_reason', 'Solicitud duplicada')
            ->assertJsonPath('data.canceller.id', $admin->id);
        $this->postJson("/api/admin/inventory/transfers/{$transferId}/cancel", [
            'reason' => 'Solicitud duplicada',
        ])->assertOk();

        $this->postJson("/api/admin/inventory/transfers/{$transferId}/cancel", [
            'reason' => 'Razón diferente',
        ])->assertUnprocessable()->assertJsonValidationErrors('reason');

        $this->assertDatabaseCount('inventory_movements', 0);
        $this->assertDatabaseCount('inventory_stocks', 0);
        $this->assertNotNull(InventoryTransfer::findOrFail($transferId)->cancelled_at);
    }

    public function test_in_transit_and_received_transfers_cannot_be_cancelled_or_redispatched(): void
    {
        $this->admin();
        [$source, $destination] = $this->branches();
        $product = $this->product();
        $this->seedStock($source, $product, 2);
        $transferId = $this->requestTransfer($source, $destination, $product, 1);
        $this->postJson("/api/admin/inventory/transfers/{$transferId}/dispatch")->assertOk();

        $this->postJson("/api/admin/inventory/transfers/{$transferId}/cancel", [
            'reason' => 'Demasiado tarde',
        ])->assertUnprocessable()->assertJsonValidationErrors('status');

        $this->postJson("/api/admin/inventory/transfers/{$transferId}/receive")->assertOk();
        $this->postJson("/api/admin/inventory/transfers/{$transferId}/dispatch")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');
        $this->postJson("/api/admin/inventory/transfers/{$transferId}/cancel", [
            'reason' => 'Terminal',
        ])->assertUnprocessable()->assertJsonValidationErrors('status');
    }

    public function test_transfer_list_supports_filters_search_dates_and_real_pagination(): void
    {
        $this->admin();
        [$source, $destination] = $this->branches();
        $third = $this->branch([
            'code' => 'MED',
            'slug' => 'medellin',
            'name' => 'Medellín',
            'city' => 'Medellín',
        ]);
        $product = $this->product();
        $requestedId = $this->requestTransfer($source, $destination, $product, 1, 'filter-requested');
        $cancelledId = $this->requestTransfer($third, $source, $product, 1, 'filter-cancelled');
        $this->postJson("/api/admin/inventory/transfers/{$cancelledId}/cancel", [
            'reason' => 'Filtro',
        ])->assertOk();
        $number = InventoryTransfer::findOrFail($requestedId)->number;
        $today = now()->toDateString();

        $this->getJson("/api/admin/inventory/transfers?status=requested&source_branch_id={$source->id}&destination_branch_id={$destination->id}&branch_id={$source->id}&from={$today}&to={$today}&search={$number}&per_page=1")
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.per_page', 1)
            ->assertJsonPath('data.data.0.id', $requestedId);

        $this->getJson('/api/admin/inventory/transfers?per_page=1&page=1')
            ->assertOk()
            ->assertJsonPath('data.total', 2)
            ->assertJsonPath('data.per_page', 1)
            ->assertJsonPath('data.current_page', 1);
    }

    public function test_show_returns_reduced_branches_items_and_actors_without_sensitive_user_fields(): void
    {
        $admin = $this->admin();
        [$source, $destination] = $this->branches();
        $product = $this->product();
        $transferId = $this->requestTransfer($source, $destination, $product, 1);

        $this->getJson("/api/admin/inventory/transfers/{$transferId}")
            ->assertOk()
            ->assertJsonPath('data.source_branch.code', 'BAQ')
            ->assertJsonPath('data.destination_branch.code', 'BOG')
            ->assertJsonPath('data.items.0.product.id', $product->id)
            ->assertJsonPath('data.requester.id', $admin->id)
            ->assertJsonMissingPath('data.requester.email')
            ->assertJsonMissingPath('data.requester.password')
            ->assertJsonMissingPath('data.requester.permissions');
    }

    public function test_transfer_delete_route_does_not_exist(): void
    {
        $this->admin();
        [$source, $destination] = $this->branches();
        $product = $this->product();
        $transferId = $this->requestTransfer($source, $destination, $product, 1);

        $this->deleteJson("/api/admin/inventory/transfers/{$transferId}")->assertMethodNotAllowed();
        $this->assertDatabaseHas('inventory_transfers', ['id' => $transferId]);
    }

    private function admin(): User
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'is_active' => true]);
        Sanctum::actingAs($admin);

        return $admin;
    }

    /** @return array{Branch, Branch} */
    private function branches(): array
    {
        return [
            $this->branch(),
            $this->branch([
                'code' => 'BOG',
                'slug' => 'bogota',
                'name' => 'Bogotá',
                'city' => 'Bogotá',
            ]),
        ];
    }

    private function branch(array $overrides = []): Branch
    {
        return Branch::create([
            'code' => 'BAQ',
            'slug' => 'barranquilla',
            'name' => 'Barranquilla',
            'city' => 'Barranquilla',
            'is_active' => true,
            ...$overrides,
        ]);
    }

    private function product(?string $name = null, ?string $sku = null): Product
    {
        $unique = uniqid();

        return Product::create([
            'name' => $name ?? "Producto {$unique}",
            'slug' => "producto-{$unique}",
            'sku' => $sku ?? "P-{$unique}",
            'price' => 100000,
            'cost_price' => 40000,
            'is_active' => true,
            'is_visible' => true,
        ]);
    }

    private function variant(Product $product, string $name, ?string $sku = null): ProductVariant
    {
        $unique = uniqid();

        return ProductVariant::create([
            'product_id' => $product->id,
            'name' => $name,
            'normalized_name' => strtolower($name),
            'sku' => $sku ?? "V-{$unique}",
            'price' => 120000,
            'cost_price' => 50000,
            'is_default' => true,
            'is_active' => true,
            'is_visible' => true,
        ]);
    }

    private function seedStock(Branch $branch, Product $product, int $quantity, ?ProductVariant $variant = null): void
    {
        $this->postJson('/api/admin/inventory/movements', [
            'branch_id' => $branch->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant?->id,
            'type' => 'entry',
            'quantity' => $quantity,
            'reason' => 'Saldo de prueba',
        ])->assertCreated();
    }

    private function requestTransfer(
        Branch $source,
        Branch $destination,
        Product $product,
        int $quantity,
        ?string $clientRequestKey = null,
        ?ProductVariant $variant = null,
    ): int {
        return (int) $this->postJson('/api/admin/inventory/transfers', $this->transferPayload($source, $destination, [[
            'product_id' => $product->id,
            'product_variant_id' => $variant?->id,
            'quantity' => $quantity,
        ]], clientRequestKey: $clientRequestKey))->assertCreated()->json('data.id');
    }

    private function transferPayload(
        Branch $source,
        Branch $destination,
        array $items,
        ?string $notes = null,
        ?string $clientRequestKey = null,
    ): array {
        return [
            'source_branch_id' => $source->id,
            'destination_branch_id' => $destination->id,
            'items' => $items,
            'notes' => $notes,
            'client_request_key' => $clientRequestKey,
        ];
    }

    private function stockQuantity(Branch $branch, Product $product, ?ProductVariant $variant = null): int
    {
        $item = $variant
            ? InventoryItem::where('product_variant_id', $variant->id)->firstOrFail()
            : InventoryItem::where('product_id', $product->id)->firstOrFail();

        return (int) InventoryStock::query()
            ->where('branch_id', $branch->id)
            ->where('inventory_item_id', $item->id)
            ->value('quantity');
    }
}
