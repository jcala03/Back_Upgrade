<?php

namespace App\Services;

use App\Exceptions\WompiWebhookException;
use App\Models\Order;
use App\Models\Payment;
use App\Models\WebhookEvent;
use App\Support\Payments\WompiTransaction;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use JsonException;

class WompiWebhookService
{
    public function __construct(
        private readonly WompiWebhookSignatureService $signature,
        private readonly WompiClient $client,
        private readonly WompiPaymentTransitionService $transitions,
        private readonly WompiPaymentReconciliationService $reconciliation,
        private readonly PaymentReconciliationResolutionService $reviews,
    ) {}

    /** Authenticated private verification precedes every normal financial transition. */
    public function receive(string $raw, ?string $checksum): array
    {
        try {
            $payload = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new WompiWebhookException('WOMPI_MALFORMED_EVENT');
        }
        if (! is_array($payload) || ! is_string($payload['event'] ?? null)
            || ! preg_match('/^[a-z_]+\.[a-z_]+$/D', $payload['event']) || strlen($payload['event']) > 120) {
            throw new WompiWebhookException('WOMPI_MALFORMED_EVENT');
        }
        $this->signature->validate($payload, $checksum);
        // Unknown event types have no transaction identity; acknowledge without storing arbitrary payloads.
        if ($payload['event'] !== 'transaction.updated') {
            return ['status' => 'ignored', 'http' => 200];
        }
        $event = $payload['data']['transaction'] ?? null;
        if (! is_array($event) || ! is_string($event['id'] ?? null)
            || ! preg_match('/^[A-Za-z0-9_-]{1,255}$/D', $event['id'])
            || ! is_string($event['status'] ?? null) || ! preg_match('/^[A-Z_]{1,40}$/D', $event['status'])) {
            throw new WompiWebhookException('WOMPI_MALFORMED_EVENT');
        }
        foreach (['reference', 'currency'] as $field) {
            if (array_key_exists($field, $event) && (! is_string($event[$field]) || $event[$field] === '' || strlen($event[$field]) > 255)) {
                throw new WompiWebhookException('WOMPI_MALFORMED_EVENT');
            }
        }
        if (array_key_exists('amount_in_cents', $event) && (! is_int($event['amount_in_cents']) || $event['amount_in_cents'] < 1)) {
            throw new WompiWebhookException('WOMPI_MALFORMED_EVENT');
        }
        // Fixed positional JSON tuple; optional fields use null, timestamp/signature excluded.
        $key = hash('sha256', json_encode([
            $payload['environment'], $payload['event'], $event['id'], $event['status'],
            $event['reference'] ?? null, $event['amount_in_cents'] ?? null, $event['currency'] ?? null,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        try {
            WebhookEvent::create([
                'provider' => Payment::PROVIDER_WOMPI, 'event_key' => $key,
                'event_type' => $payload['event'], 'provider_transaction_id' => $event['id'],
                'payload_hash' => hash('sha256', $raw), 'status' => WebhookEvent::STATUS_RECEIVED,
                'received_at' => now(), 'metadata' => ['environment' => $payload['environment']],
            ]);
        } catch (UniqueConstraintViolationException $exception) {
            if (! WebhookEvent::where('provider', Payment::PROVIDER_WOMPI)->where('event_key', $key)->exists()) {
                throw $exception;
            }
        }

        // Concurrent deliveries serialize on the ledger row. A crash rolls back processing,
        // leaving received (or retryable failed) available for the next delivery.
        return DB::transaction(function () use ($key, $event) {
            $ledger = WebhookEvent::where('provider', Payment::PROVIDER_WOMPI)->where('event_key', $key)->lockForUpdate()->firstOrFail();
            // Revisit only previously deferred financial cases from 7B-2B-1, after fresh verification.
            $deferredFinancial = in_array($event['status'], ['APPROVED', 'VOIDED'], true)
                && in_array($ledger->last_error, ['WOMPI_TRANSITION_OUT_OF_SCOPE', 'WOMPI_TRANSACTION_ID_CONFLICT', 'WOMPI_TRANSACTION_ID_MISMATCH'], true);
            if (in_array($ledger->status, [WebhookEvent::STATUS_PROCESSED, WebhookEvent::STATUS_REQUIRES_RECONCILIATION, WebhookEvent::STATUS_IGNORED], true)
                || ($ledger->status === WebhookEvent::STATUS_FAILED && ! ($ledger->metadata['retryable'] ?? false) && ! $deferredFinancial)) {
                return ['status' => $ledger->status, 'http' => 200];
            }
            try {
                $payment = null;
                $transaction = $this->client->getTransaction($event['id']);
                $payment = $this->match($event, $transaction);
                if (! $transaction->supported()) {
                    $ledger->update(['status' => WebhookEvent::STATUS_IGNORED, 'processed_at' => null,
                        'last_error' => 'WOMPI_UNSUPPORTED_STATUS',
                        'metadata' => ['environment' => $ledger->metadata['environment'], 'retryable' => false]]);

                    return ['status' => 'ignored', 'http' => 200];
                }
                $ledger->update(['status' => WebhookEvent::STATUS_VERIFIED, 'processed_at' => null, 'last_error' => null,
                    'metadata' => ['environment' => $ledger->metadata['environment'], 'provider_status' => $transaction->status, 'retryable' => false]]);

                try {
                    // Savepoint ensures even a handled domain/unique failure cannot commit partial financial writes.
                    DB::transaction(function () use ($payment, $transaction, $ledger) {
                        if ($payment->transaction_id !== null && $payment->transaction_id !== $transaction->transactionId) {
                            $this->reconciliation->mark($payment, WompiPaymentReconciliationService::TRANSACTION_CONFLICT);
                        } else {
                            $this->transitions->apply($payment, $payment->order, $transaction);
                        }
                        $this->finish($ledger, $payment);
                    });
                } catch (UniqueConstraintViolationException $exception) {
                    // Do not misclassify unrelated uniqueness errors from e.g. notification delivery.
                    $owner = Payment::where('provider', Payment::PROVIDER_WOMPI)->where('transaction_id', $transaction->transactionId)->sharedLock()->first();
                    if (! $owner || $owner->id === $payment->id) {
                        throw $exception;
                    }
                    $payment->refresh();
                    $this->reconciliation->mark($payment, WompiPaymentReconciliationService::TRANSACTION_CONFLICT);
                    $this->finish($ledger, $payment);
                }

                return ['status' => $ledger->status, 'http' => 200];
            } catch (WompiWebhookException $exception) {
                $retryable = $exception->httpStatus >= 500;
                $ledger->update(['status' => WebhookEvent::STATUS_FAILED, 'processed_at' => null, 'last_error' => $exception->errorCode,
                    'metadata' => ['environment' => $ledger->metadata['environment'], 'retryable' => $retryable]]);

                if ($this->reviews->supportsReason($exception->errorCode)) {
                    $reviewPayment = $exception->paymentId === null
                        ? null
                        : Payment::query()->find($exception->paymentId);
                    $reviewOrder = $exception->orderId === null
                        ? null
                        : Order::query()->find($exception->orderId);
                    $this->reviews->detect($exception->errorCode, $ledger, $reviewPayment, $reviewOrder);
                }

                // Permanent inconsistencies are explicitly audited, acknowledged, never applied.
                return ['status' => 'failed', 'http' => $retryable ? $exception->httpStatus : 200];
            }
        });
    }

    private function match(array $event, WompiTransaction $transaction): Payment
    {
        foreach (['id' => $transaction->transactionId, 'status' => $transaction->status,
            'reference' => $transaction->reference, 'amount_in_cents' => $transaction->amountInCents, 'currency' => $transaction->currency] as $field => $expected) {
            if (array_key_exists($field, $event) && $event[$field] !== $expected) {
                throw new WompiWebhookException('WOMPI_EVENT_'.strtoupper($field).'_MISMATCH', 409);
            }
        }
        $payment = Payment::where('provider', Payment::PROVIDER_WOMPI)->where('reference', $transaction->reference)->lockForUpdate()->first();
        if (! $payment) {
            throw new WompiWebhookException('WOMPI_PAYMENT_NOT_FOUND', 409);
        }
        $order = Order::whereKey($payment->order_id)->lockForUpdate()->first();
        // SQL collations may be case-insensitive: domain identifiers are not.
        if ($payment->provider !== Payment::PROVIDER_WOMPI || $payment->reference !== $transaction->reference) {
            throw new WompiWebhookException('WOMPI_REFERENCE_MISMATCH', 409);
        }
        $amount = filter_var($payment->getRawOriginal('amount'), FILTER_VALIDATE_INT);
        if ($amount === false || $amount < 1 || $amount > intdiv(PHP_INT_MAX, 100) || $amount * 100 !== $transaction->amountInCents) {
            throw new WompiWebhookException('WOMPI_AMOUNT_MISMATCH', 409, $payment->id, $order?->id);
        }
        if ($payment->currency !== $transaction->currency) {
            throw new WompiWebhookException('WOMPI_CURRENCY_MISMATCH', 409, $payment->id, $order?->id);
        }
        if (! $order || $order->currency !== $transaction->currency || $order->origin !== Order::ORIGIN_ECOMMERCE) {
            throw new WompiWebhookException('WOMPI_ORDER_MISMATCH', 409, $payment->id, $order?->id);
        }

        $payment->setRelation('order', $order);

        return $payment;
    }

    private function finish(WebhookEvent $ledger, Payment $payment): void
    {
        $requiresReconciliation = $payment->reconciliation_required_at !== null;
        if ($requiresReconciliation) {
            $this->reviews->detect(
                (string) $payment->reconciliation_reason,
                $ledger,
                $payment,
                $payment->order,
            );
        }
        $ledger->update(['status' => $requiresReconciliation
            ? WebhookEvent::STATUS_REQUIRES_RECONCILIATION : WebhookEvent::STATUS_PROCESSED,
            'processed_at' => now(), 'last_error' => null]);
    }
}
