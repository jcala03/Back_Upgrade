<?php

namespace Tests\Feature;

use App\Mail\CommercialDocumentMail;
use App\Mail\OrderCreatedMail;
use App\Models\Branch;
use App\Models\CrmNotification;
use App\Models\Employee;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Quotation;
use App\Models\User;
use App\Services\BusinessSettingsService;
use App\Services\CommercialDocumentData;
use App\Services\CommercialDocumentPdf;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CommercialAccess;
use Tests\TestCase;

class CommercialDocumentsTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('upgrade_test', DB::selectOne('SELECT DATABASE() AS db')->db);
        Http::preventStrayRequests();
        Queue::fake();
        $this->withoutMiddleware(ThrottleRequests::class);
        config(['mail.default' => 'array']);
    }

    private function fixture(): array
    {
        $tag = 'doc-'.Str::lower(Str::random(10));
        $branch = Branch::create(['name' => 'Sede documentos QA', 'code' => substr($tag, -10), 'slug' => $tag, 'city' => 'Barranquilla', 'is_active' => true]);
        $user = User::factory()->create(['role' => 'user', 'is_active' => true]);
        $employee = Employee::create(['user_id' => $user->id, 'branch_id' => $branch->id, 'name' => 'Vendedor QA', 'job_title' => 'Asesor QA', 'is_active' => true]);
        $customer = ['customer_name' => 'Cliente histórico Ñ', 'customer_email' => 'cliente@example.test', 'customer_phone' => '3001234567',
            'customer_document' => 'DOCUMENT-PRIVATE', 'customer_notes' => 'INTERNAL-NOTE-PRIVATE', 'branch_id' => $branch->id, 'sales_employee_id' => $employee->id];
        $quote = Quotation::create($customer + ['quotation_number' => 'QA-COT-'.$tag, 'status' => 'draft',
            'subtotal' => 1200000, 'discount_total' => 20000, 'total' => 1180000, 'valid_until' => now()->addDays(15)]);
        $line = ['item_type' => 'product', 'product_name' => 'Pantalla histórica <script>sin ejecutar</script>', 'variant_name' => 'Kit histórico', 'variant_sku' => 'QA-SNAPSHOT',
            'variant_specs' => ['ram_gb' => 8, 'almacenamiento_gb' => 128], 'unit_price' => 1200000, 'unit_cost' => 99112233,
            'quantity' => 1, 'subtotal' => 1200000, 'discount_amount' => 20000, 'total' => 1180000];
        $quote->items()->create($line);
        $order = Order::create($customer + ['order_number' => 'QA-ORD-'.$tag, 'status' => 'pending', 'origin' => 'crm', 'payment_status' => 'unpaid',
            'subtotal' => 1200000, 'discount_total' => 20000, 'total' => 1180000]);
        $order->items()->create($line + ['configuration_context' => ['vehicle_brand_name' => 'BMW histórico', 'vehicle_model_name' => 'Serie 3',
            'vehicle_version_name' => 'F30', 'vehicle_multimedia_system_name' => 'CIC', 'ram_gb' => 8, 'almacenamiento_gb' => 128]]);

        return compact('user', 'employee', 'quote', 'order');
    }

    public function test_notification_count_quotation_listing_and_sales_keep_authentication_and_permissions(): void
    {
        extract($this->fixture());
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        foreach ([$user, $admin] as $actor) {
            foreach ([null, now()] as $readAt) {
                CrmNotification::create(['user_id' => $actor->id, 'type' => CrmNotification::TYPE_TASK_ASSIGNED,
                    'severity' => CrmNotification::SEVERITY_INFO, 'title' => 'QA lectura CRM',
                    'message' => 'Notificación sintética de regresión', 'read_at' => $readAt]);
            }
        }
        $this->getJson('/api/admin/notifications/unread-count')->assertUnauthorized();
        $this->getJson('/api/admin/quotations?page=1&per_page=20')->assertUnauthorized();
        Sanctum::actingAs($admin);
        for ($i = 0; $i < 3; $i++) {
            $this->getJson('/api/admin/notifications/unread-count')->assertOk()->assertJsonPath('data.count', 1);
            $this->getJson('/api/admin/quotations?page=1&per_page=20')->assertOk()
                ->assertJsonPath('data.per_page', 20)->assertJsonFragment(['quotation_number' => $quote->quotation_number]);
        }
        $this->getJson('/api/admin/orders?page=1&per_page=20')->assertOk();
        Sanctum::actingAs($user);
        $this->getJson('/api/admin/notifications/unread-count')->assertOk()->assertJsonPath('data.count', 1);
        $this->getJson('/api/admin/quotations?page=1&per_page=20')->assertForbidden();
        $this->getJson('/api/my/quotations?page=1&per_page=20')->assertOk();
        $this->getJson('/api/my/sales?page=1&per_page=20')->assertOk();
        $admin->update(['is_active' => false]);
        Sanctum::actingAs($admin);
        $this->getJson('/api/admin/notifications/unread-count')->assertForbidden();
        $this->getJson('/api/admin/quotations?page=1&per_page=20')->assertForbidden();
    }

    public function test_guests_and_users_cannot_read_admin_or_other_employee_documents(): void
    {
        extract($this->fixture());
        foreach (["admin/quotations/{$quote->id}", "admin/orders/{$order->id}", "my/quotations/{$quote->id}", "my/sales/{$order->id}"] as $path) {
            $this->getJson('/api/'.$path.'/pdf')->assertUnauthorized();
        }
        Sanctum::actingAs($user);
        $this->getJson("/api/admin/quotations/{$quote->id}/pdf")->assertForbidden();
        $this->getJson("/api/admin/orders/{$order->id}/pdf")->assertForbidden();
        $other = User::factory()->create(['role' => 'user']);
        Employee::create(['user_id' => $other->id, 'branch_id' => $employee->branch_id, 'name' => 'Otro asesor QA', 'job_title' => 'Asesor QA', 'is_active' => true]);
        Sanctum::actingAs($other);
        $this->getJson("/api/my/quotations/{$quote->id}/document")->assertNotFound();
        $this->postJson("/api/my/sales/{$order->id}/email", ['email' => 'x@example.test'])->assertNotFound();
        $this->getJson("/api/my/sales/{$order->id}/pdf")->assertNotFound();
    }

    public function test_owner_and_admin_download_real_pdfs_without_mutating_records(): void
    {
        extract($this->fixture());
        $before = [$quote->fresh()->toArray(), $order->fresh()->toArray(), DB::table('inventory_movements')->count(), DB::table('payments')->count()];
        $directories = glob(sys_get_temp_dir().'/upgrade79-pdf-*');
        foreach ([$user, User::factory()->create(['role' => 'admin'])] as $actor) {
            Sanctum::actingAs($actor);
            $own = $actor->role === 'user';
            foreach ([$quote, $order] as $record) {
                $quotation = $record instanceof Quotation;
                $path = ($own ? ($quotation ? 'my/quotations/' : 'my/sales/') : ($quotation ? 'admin/quotations/' : 'admin/orders/')).$record->id;
                $response = $this->getJson('/api/'.$path.'/pdf?filename=../../wrong.pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf');
                $this->assertStringStartsWith('%PDF-', $response->getContent());
                $this->assertStringContainsString(sprintf('%s-%06d.pdf', $quotation ? 'COT' : 'ORDEN', $record->id), $response->headers->get('Content-Disposition'));
                $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
                $this->getJson('/api/'.$path.'/document')->assertOk()->assertJsonPath('data.email_simulated', true)
                    ->assertJsonMissingPath('data.customer.document')->assertJsonMissingPath('data.items');
            }
        }
        $this->assertSame($before, [$quote->fresh()->toArray(), $order->fresh()->toArray(), DB::table('inventory_movements')->count(), DB::table('payments')->count()]);
        $this->assertSame($directories, glob(sys_get_temp_dir().'/upgrade79-pdf-*'));
        Http::assertNothingSent();
    }

    public function test_snapshots_and_configuration_are_not_replaced_or_exposed_with_internal_fields(): void
    {
        extract($this->fixture());
        $dto = app(CommercialDocumentData::class)->make($order);
        $this->assertContains('Sistema OEM: CIC', $dto['items'][0]['configuration']);
        $this->assertContains('RAM: 8 GB', $dto['items'][0]['configuration']);
        $this->assertContains('Almacenamiento: 128 GB', $dto['items'][0]['configuration']);
        $html = view('documents.commercial', ['document' => $dto, 'logo' => ''])->render();
        $this->assertStringContainsString('&lt;script&gt;', $html);
        foreach (['99112233', 'DOCUMENT-PRIVATE', 'INTERNAL-NOTE-PRIVATE', '<script>'] as $private) {
            $this->assertStringNotContainsString($private, $html);
        }
        $quotation = app(CommercialDocumentData::class)->make($quote);
        $this->assertContains('Sistema OEM: no consta en el registro histórico.', $quotation['items'][0]['configuration']);
        $this->assertSame('Kit histórico', $quotation['items'][0]['variant']);
    }

    public function test_pending_partial_cancelled_refunded_and_inconsistent_payments_are_not_paid_receipts(): void
    {
        extract($this->fixture());
        foreach ([['pending', 'unpaid'], ['confirmed', 'partial'], ['cancelled', 'paid'], ['completed', 'refunded'], ['completed', 'paid']] as [$status, $payment]) {
            $order->update(['status' => $status, 'payment_status' => $payment]);
            $dto = app(CommercialDocumentData::class)->make($order);
            $this->assertNotSame('paid_sale', $dto['kind']);
            $this->assertStringStartsWith('ORDEN-', $dto['filename']);
            $this->assertStringContainsString('no acredita una venta pagada', $dto['notice']);
        }
        Payment::create(['order_id' => $order->id, 'amount' => 1180000, 'status' => 'completed', 'method' => 'cash', 'paid_at' => now()]);
        $dto = app(CommercialDocumentData::class)->make($order);
        $this->assertSame('paid_sale', $dto['kind']);
        $this->assertSame(sprintf('VENTA-%06d.pdf', $order->id), $dto['filename']);
        $this->assertSame(0, $dto['balance']);
        $this->assertStringContainsString('No es factura electrónica DIAN', $dto['notice']);
    }

    public function test_local_mail_is_forced_to_array_with_real_attachment_and_reviewed_recipient(): void
    {
        extract($this->fixture());
        config(['mail.default' => 'smtp', 'commercial_documents.real_mail_enabled' => true, 'commercial_documents.mailer' => 'smtp']);
        Mail::mailer('array')->getSymfonyTransport()->flush();
        Sanctum::actingAs($user);
        $this->postJson("/api/my/quotations/{$quote->id}/email", ['email' => 'revisado@example.test'])->assertOk()->assertJsonPath('data.simulated', true);
        $messages = Mail::mailer('array')->getSymfonyTransport()->messages();
        $this->assertCount(1, $messages);
        $message = $messages->first()->getOriginalMessage();
        $this->assertSame('ventas@upgradecolombia.com', $message->getFrom()[0]->getAddress());
        $this->assertSame('revisado@example.test', $message->getTo()[0]->getAddress());
        $this->assertCount(1, $message->getAttachments());
        $this->assertSame('application/pdf', $message->getAttachments()[0]->getMediaType().'/'.$message->getAttachments()[0]->getMediaSubtype());
        $this->assertStringStartsWith('%PDF-', $message->getAttachments()[0]->getBody());
        $this->assertSame('draft', $quote->fresh()->status);
        $this->assertNull($quote->fresh()->order_id);
    }

    public function test_admin_uses_existing_update_permission_and_inactive_accounts_are_rejected(): void
    {
        extract($this->fixture());
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $this->assertFalse($admin->hasPermission('quotations.send'));
        $this->assertTrue($admin->hasPermission('quotations.update'));
        Sanctum::actingAs($admin);
        $this->getJson("/api/admin/quotations/{$quote->id}/document")->assertOk()->assertJsonPath('data.can_email', true);
        $this->postJson("/api/admin/quotations/{$quote->id}/email", ['email' => 'admin-review@example.test'])->assertOk()->assertJsonPath('data.simulated', true);
        $limited = CommercialAccess::limited($admin, ['quotations.view', 'orders.view']);
        Sanctum::actingAs($limited);
        $this->getJson("/api/admin/orders/{$order->id}/document")->assertOk()->assertJsonPath('data.can_email', false);
        $this->postJson("/api/admin/quotations/{$quote->id}/email", ['email' => 'x@example.test'])->assertForbidden();
        $user->update(['is_active' => false]);
        Sanctum::actingAs($user);
        $this->getJson("/api/my/quotations/{$quote->id}/pdf")->assertForbidden();
        $this->postJson("/api/my/sales/{$order->id}/email", ['email' => 'x@example.test'])->assertForbidden();
    }

    public function test_production_keeps_simulation_until_explicit_real_mail_activation(): void
    {
        extract($this->fixture());
        $this->app->instance('env', 'production');
        config(['commercial_documents.real_mail_enabled' => false, 'commercial_documents.mailer' => 'smtp']);
        Mail::mailer('array')->getSymfonyTransport()->flush();
        Sanctum::actingAs($user);
        $this->postJson("/api/my/sales/{$order->id}/email", ['email' => 'never-delivered@example.test'])
            ->assertOk()->assertJsonPath('data.simulated', true);
        $this->assertCount(1, Mail::mailer('array')->getSymfonyTransport()->messages());
        $this->assertSame('unpaid', $order->fresh()->payment_status);
    }

    public function test_existing_order_email_serialization_still_works_with_patched_mime(): void
    {
        extract($this->fixture());
        Mail::mailer('array')->getSymfonyTransport()->flush();
        Mail::mailer('array')->to('regresion@example.test')->send(new OrderCreatedMail(
            $order, app(BusinessSettingsService::class)->identity(),
        ));
        $messages = Mail::mailer('array')->getSymfonyTransport()->messages();
        $this->assertCount(1, $messages);
        $message = $messages->first()->getOriginalMessage();
        $this->assertStringContainsString($order->order_number, $message->getSubject());
        $this->assertCount(0, $message->getAttachments());
    }

    public function test_service_snapshots_charges_and_completed_payments_keep_their_recorded_amounts(): void
    {
        extract($this->fixture());
        $order->items()->create(['item_type' => 'service', 'service_name' => 'Instalación histórica QA', 'quantity' => 2,
            'unit_price' => 35000, 'unit_cost' => 99112233, 'subtotal' => 70000, 'discount_amount' => 0, 'total' => 70000]);
        $order->charges()->createMany([
            ['type' => 'tax', 'label' => 'Impuesto registrado', 'amount' => 10000],
            ['type' => 'shipping', 'label' => 'Envío registrado', 'amount' => 15000],
        ]);
        $order->update(['subtotal' => 1270000, 'charges_total' => 25000, 'total' => 1275000, 'payment_status' => 'partial']);
        foreach ([['pending', 900000], ['failed', 900000], ['completed', 300000]] as [$status, $amount]) {
            Payment::create(['order_id' => $order->id, 'method' => 'cash', 'status' => $status, 'amount' => $amount]);
        }
        $dto = app(CommercialDocumentData::class)->make($order);
        $this->assertSame('Instalación histórica QA', $dto['items'][1]['name']);
        $this->assertSame(2, $dto['items'][1]['quantity']);
        $this->assertSame(35000, $dto['items'][1]['unit_price']);
        $this->assertSame(25000, $dto['charges_total']);
        $this->assertSame(1275000, $dto['total']);
        $this->assertSame(300000, $dto['paid']);
        $this->assertSame(975000, $dto['balance']);
        $html = view('documents.commercial', ['document' => $dto, 'logo' => ''])->render();
        foreach (['Instalación histórica QA', 'Impuesto registrado', 'Envío registrado', '1.275.000', '975.000'] as $value) {
            $this->assertStringContainsString($value, $html);
        }
        $this->assertStringNotContainsString('99112233', $html);
    }

    public function test_email_requires_send_permission_and_rejects_header_injection_or_attachment_paths(): void
    {
        extract($this->fixture());
        $limited = CommercialAccess::limited($user, ['quotations.view_own', 'orders.view_own']);
        $limited->setRelation('employee', $employee);
        Sanctum::actingAs($limited);
        $this->getJson("/api/my/quotations/{$quote->id}/document")->assertOk()->assertJsonPath('data.can_email', false);
        $this->postJson("/api/my/quotations/{$quote->id}/email", ['email' => 'x@example.test'])->assertForbidden();
        $this->postJson("/api/my/sales/{$order->id}/email", ['email' => 'x@example.test'])->assertForbidden();
        Sanctum::actingAs($user);
        foreach ([['email' => "x@example.test\r\nBcc: y@example.test"], ['email' => 'x@example.test', 'path' => '/etc/passwd'],
            ['email' => 'x@example.test', 'bcc' => 'y@example.test'], ['email' => ['x@example.test']], []] as $payload) {
            $this->postJson("/api/my/quotations/{$quote->id}/email", $payload)->assertUnprocessable();
        }
    }

    public function test_mail_failure_does_not_claim_success_or_change_business_state(): void
    {
        extract($this->fixture());
        Sanctum::actingAs($user);
        Mail::shouldReceive('mailer')->once()->with('array')->andThrow(new \RuntimeException('Synthetic transport error'));
        $this->postJson("/api/my/sales/{$order->id}/email", ['email' => 'x@example.test'])->assertStatus(503);
        $this->assertSame('pending', $order->fresh()->status);
        $this->assertSame('unpaid', $order->fresh()->payment_status);
    }

    public function test_document_reads_do_not_consume_email_limit_and_repeated_email_is_limited(): void
    {
        extract($this->fixture());
        $this->withMiddleware(ThrottleRequests::class);
        Sanctum::actingAs($user);
        for ($i = 0; $i < 6; $i++) {
            $this->getJson("/api/my/quotations/{$quote->id}/document")->assertOk();
        }
        Mail::fake();
        for ($i = 0; $i < 5; $i++) {
            $this->postJson("/api/my/quotations/{$quote->id}/email", ['email' => 'x@example.test'])->assertOk();
        }
        $this->postJson("/api/my/quotations/{$quote->id}/email", ['email' => 'x@example.test'])->assertStatus(429);
        $this->getJson("/api/my/quotations/{$quote->id}/document")->assertOk();
        Mail::assertSent(CommercialDocumentMail::class, 5);
    }

    public function test_regeneration_and_multiple_pages_need_no_saved_pdf_or_current_catalog(): void
    {
        extract($this->fixture());
        $original = $quote->items()->first()->attributesToArray();
        unset($original['id'], $original['quotation_id'], $original['created_at'], $original['updated_at']);
        for ($i = 0; $i < 35; $i++) {
            $quote->items()->create(array_replace($original, ['product_name' => 'Línea de prueba extensa número '.$i]));
        }
        $quote->update(['subtotal' => 1200000 * 36, 'discount_total' => 20000 * 36, 'total' => 1180000 * 36]);
        $dto = app(CommercialDocumentData::class)->make($quote);
        $pdf = app(CommercialDocumentPdf::class)->render($dto);
        $this->assertGreaterThan(10000, strlen($pdf));
        $again = app(CommercialDocumentPdf::class)->render($dto);
        $this->assertStringStartsWith('%PDF-', $again);
        $this->assertCount(36, $quote->fresh()->items);
        if ($directory = getenv('COMMERCIAL_DOCUMENTS_QA_DIR')) {
            $path = realpath($directory);
            $this->assertStringStartsWith('/tmp/upgrade79-documents-qa-', $path);
            file_put_contents($path.'/cotizacion.pdf', $pdf);
            $order->update(['status' => 'completed', 'payment_status' => 'paid']);
            Payment::create(['order_id' => $order->id, 'amount' => 1180000, 'status' => 'completed', 'method' => 'cash']);
            file_put_contents($path.'/venta.pdf', app(CommercialDocumentPdf::class)->render(app(CommercialDocumentData::class)->make($order)));
        }
    }
}
