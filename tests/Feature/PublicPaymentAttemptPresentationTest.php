<?php

namespace Tests\Feature;

use App\Http\Resources\PublicOrderResource;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class PublicPaymentAttemptPresentationTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_attempt_status_distinguishes_pending_declined_error_and_approval(): void
    {
        $order = Order::create(['order_number' => 'QA-PRESENTATION', 'status' => 'confirmed', 'payment_status' => 'unpaid', 'total' => 100, 'subtotal' => 100]);
        $this->assertNull($order->publicPaymentAttemptStatus());
        $payment = $order->payments()->create(['amount' => 100, 'method' => 'wompi', 'status' => 'pending', 'provider_status' => 'PENDING']);
        $this->assertSame('PENDING', $order->publicPaymentAttemptStatus());
        foreach (['DECLINED', 'ERROR', 'VOIDED'] as $status) {
            $payment->update(['status' => 'failed', 'provider_status' => $status]);
            $this->assertSame($status, $order->publicPaymentAttemptStatus());
        }
        $payment->update(['status' => 'completed', 'provider_status' => 'APPROVED']);
        $this->assertSame('APPROVED', $order->publicPaymentAttemptStatus());
        $order->update(['payment_status' => 'paid']);
        $this->assertSame('confirmed', $order->fresh()->status);
        $payment->update(['status' => 'refunded', 'provider_status' => 'VOIDED']);
        $this->assertSame('VOIDED', $order->publicPaymentAttemptStatus());
    }

    public function test_latest_retry_overrides_old_decline_and_public_response_is_whitelisted(): void
    {
        $order = Order::create(['order_number' => 'QA-RETRY-PRESENTATION', 'status' => 'confirmed', 'payment_status' => 'unpaid', 'total' => 100, 'subtotal' => 100]);
        $order->payments()->create(['amount' => 100, 'method' => 'wompi', 'status' => 'failed', 'provider_status' => 'DECLINED', 'failure_reason' => 'PRIVATE', 'metadata' => ['secret' => 'PRIVATE']]);
        $payment = $order->payments()->create(['amount' => 100, 'method' => 'wompi', 'status' => 'pending']);
        $this->assertSame('PENDING', $order->publicPaymentAttemptStatus());
        $data = (new PublicOrderResource($order))->resolve(Request::create('/api/checkout/orders/public-token'));
        $this->assertSame('PENDING', $data['payment_attempt_status']);
        $this->assertStringNotContainsString('PRIVATE', json_encode($data));
        $this->assertArrayNotHasKey('payments', $data);
        $payment->update(['reconciliation_required_at' => now(), 'provider_status' => 'APPROVED']);
        $this->assertSame('UNKNOWN', $order->publicPaymentAttemptStatus());
        $this->assertFalse($order->canRetryPayment());
        $this->assertSame(1, Order::count());
    }
}
