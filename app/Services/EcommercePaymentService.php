<?php

namespace App\Services;

use App\Exceptions\EcommercePaymentException;
use App\Models\Order;
use App\Models\Payment;
use App\Support\Payments\CopAmount;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class EcommercePaymentService
{
    public function __construct(
        private readonly OrderService $orders,
        private readonly PublicCheckoutService $checkout,
        private readonly WompiCheckoutService $wompi,
    ) {}

    /** @return array{payload: array, replayed: bool} */
    public function initialize(string $publicToken, string $key): array
    {
        // A unique-key race rolls back the entire losing transaction, including stock.
        try {
            return $this->initializeLocked($publicToken, $key);
        } catch (UniqueConstraintViolationException $exception) {
            if (! Payment::query()->where('provider', Payment::PROVIDER_WOMPI)->where('idempotency_key', $key)->exists()) {
                throw $exception;
            }

            return $this->initializeLocked($publicToken, $key);
        }
    }

    private function initializeLocked(string $publicToken, string $key): array
    {
        return DB::transaction(function () use ($publicToken, $key) {
            $order = Order::query()->where('public_token_hash', hash('sha256', $publicToken))->lockForUpdate()->firstOrFail();
            $fingerprint = hash('sha256', json_encode([
                'order_id' => $order->id, 'provider' => Payment::PROVIDER_WOMPI,
                'contract_version' => 1, 'payload' => (object) [],
            ], JSON_THROW_ON_ERROR));
            $existing = Payment::query()->where('provider', Payment::PROVIDER_WOMPI)
                ->where('idempotency_key', $key)->lockForUpdate()->first();
            if ($existing && ((int) $existing->order_id !== $order->id
                || ! hash_equals((string) $existing->idempotency_fingerprint, $fingerprint))) {
                throw new EcommercePaymentException(EcommercePaymentException::IDEMPOTENCY_CONFLICT);
            }

            $this->assertPayable($order);
            // DB BIGINT may arrive as a string: validate before Eloquent's integer cast.
            $rawTotal = $order->getRawOriginal('total');
            $total = (is_int($rawTotal) || (is_string($rawTotal) && ctype_digit($rawTotal)))
                ? filter_var($rawTotal, FILTER_VALIDATE_INT) : false;
            CopAmount::toCents($total, $order->currency);

            if ($existing) {
                return ['payload' => $existing->metadata['checkout'], 'replayed' => true];
            }

            $this->wompi->assertConfigured();
            if ($order->status === Order::STATUS_PENDING) {
                $order->setRelation('charges', $order->charges()->lockForUpdate()->get());
                $order->setRelation('shippingAddress', $order->shippingAddress()->lockForUpdate()->first());
                if (! in_array($order->fulfillment_type, Order::fulfillmentTypes(), true)
                    || ! $this->checkout->ready($order)
                    || ! $order->items()->exists()
                    || $order->stock_committed_at !== null
                    || $order->stock_reverted_at !== null
                    || $order->stock_reservation_expires_at !== null
                ) {
                    throw new EcommercePaymentException(EcommercePaymentException::ORDER_NOT_READY_FOR_PAYMENT);
                }

                $expiresAt = now()->startOfSecond()->addMinutes($this->wompi->reservationMinutes());
                try {
                    // Existing transition revalidates branch and authoritative stock under locks.
                    $order = $this->orders->transition($order, Order::STATUS_CONFIRMED, null);
                } catch (ValidationException) {
                    throw new EcommercePaymentException(EcommercePaymentException::ORDER_NOT_READY_FOR_PAYMENT);
                }
                $order->update(['stock_reservation_expires_at' => $expiresAt]);
            }

            $payment = $order->payments()->create([
                'method' => Payment::METHOD_WOMPI,
                'provider' => Payment::PROVIDER_WOMPI,
                'status' => Payment::STATUS_PENDING,
                'amount' => $total,
                'currency' => $order->currency,
                'reference' => 'UG79-'.Str::uuid(),
                'idempotency_key' => $key,
                'idempotency_fingerprint' => $fingerprint,
                'expires_at' => $order->stock_reservation_expires_at,
            ]);
            $payload = $this->wompi->payload($payment);
            // Freeze public output across config/key rotation; never store a signing secret.
            $payment->update(['metadata' => ['checkout' => $payload]]);

            return ['payload' => $payload, 'replayed' => false];
        }, 3);
    }

    private function assertPayable(Order $order): void
    {
        if ($order->payments()->whereNotNull('reconciliation_required_at')->exists()) {
            throw new EcommercePaymentException(EcommercePaymentException::PAYMENT_RECONCILIATION_REQUIRED);
        }
        if ($order->origin !== Order::ORIGIN_ECOMMERCE
            || ! in_array($order->status, [Order::STATUS_PENDING, Order::STATUS_CONFIRMED], true)) {
            throw new EcommercePaymentException(EcommercePaymentException::ORDER_NOT_PAYABLE);
        }
        if ($order->payment_status === Order::PAYMENT_PAID
            || $order->payments()->where('status', Payment::STATUS_COMPLETED)->exists()) {
            throw new EcommercePaymentException(EcommercePaymentException::PAYMENT_ALREADY_COMPLETED);
        }
        if ($order->payment_status !== Order::PAYMENT_UNPAID) {
            throw new EcommercePaymentException(EcommercePaymentException::ORDER_NOT_PAYABLE);
        }
        if ($order->status === Order::STATUS_CONFIRMED) {
            if ($order->stock_reservation_expires_at === null
                || $order->branch_id === null
                || $order->stock_committed_at === null || $order->stock_reverted_at !== null) {
                throw new EcommercePaymentException(EcommercePaymentException::ORDER_NOT_PAYABLE);
            }
            if (! $order->stock_reservation_expires_at->isFuture()) {
                throw new EcommercePaymentException(EcommercePaymentException::RESERVATION_EXPIRED);
            }
        }
    }
}
