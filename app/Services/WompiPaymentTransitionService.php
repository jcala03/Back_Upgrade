<?php

namespace App\Services;

use App\Exceptions\WompiWebhookException;
use App\Models\Order;
use App\Models\Payment;
use App\Support\Payments\WompiTransaction;

class WompiPaymentTransitionService
{
    public function __construct(private readonly WompiPaymentReconciliationService $reconciliation) {}

    /** Caller owns WebhookEvent -> Payment -> Order locks and has revalidated the private transaction. */
    public function apply(Payment $payment, Order $order, WompiTransaction $transaction): void
    {
        if ($transaction->status === 'APPROVED'
            && ($order->status === Order::STATUS_CANCELLED || $order->stock_reverted_at !== null)) {
            $this->reconciliation->approvedForReview($payment, $transaction, WompiPaymentReconciliationService::LATE_APPROVAL);

            return;
        }
        if ($transaction->status === 'APPROVED' && $payment->status === Payment::STATUS_COMPLETED
            && $payment->provider_status === 'APPROVED' && $payment->transaction_id === $transaction->transactionId) {
            return;
        }

        if ($transaction->status === 'APPROVED'
            && $order->payments()->whereKeyNot($payment->id)->where('status', Payment::STATUS_COMPLETED)->exists()) {
            $this->reconciliation->approvedForReview($payment, $transaction, WompiPaymentReconciliationService::DUPLICATE_APPROVAL);

            return;
        }
        if ($transaction->status === 'VOIDED' && $payment->status === Payment::STATUS_COMPLETED) {
            $this->reconciliation->voidAfterApproval($payment, $order, $transaction);

            return;
        }
        if ($transaction->status === 'VOIDED' && $payment->status === Payment::STATUS_REFUNDED
            && $payment->provider_status === 'VOIDED' && $payment->reconciliation_required_at !== null) {
            return;
        }

        // The remaining cases use the unchanged normal-state guards; expiry processing is deferred.
        if (! $transaction->supported() || $payment->method !== Payment::METHOD_WOMPI
            || $order->status !== Order::STATUS_CONFIRMED || $order->payment_status !== Order::PAYMENT_UNPAID
            || $order->branch_id === null || $order->stock_committed_at === null || $order->stock_reverted_at !== null
            || $order->stock_reservation_expires_at === null
            || ! in_array($payment->status, [Payment::STATUS_PENDING, Payment::STATUS_FAILED], true)
            || $payment->paid_at !== null
            || $order->payments()->where(function ($query) {
                $query->where('status', Payment::STATUS_COMPLETED)->orWhereNotNull('reconciliation_required_at');
            })->exists()) {
            throw new WompiWebhookException('WOMPI_TRANSITION_OUT_OF_SCOPE', 409);
        }
        $total = filter_var($order->getRawOriginal('total'), FILTER_VALIDATE_INT);
        if ($total === false || $total !== $payment->amount) {
            throw new WompiWebhookException('WOMPI_ORDER_TOTAL_MISMATCH', 409);
        }
        if (in_array($transaction->status, ['PENDING', 'APPROVED'], true) && $payment->status !== Payment::STATUS_PENDING) {
            throw new WompiWebhookException('WOMPI_TRANSITION_OUT_OF_SCOPE', 409);
        }
        $values = ['transaction_id' => $transaction->transactionId, 'provider_status' => $transaction->status];
        if ($transaction->status === 'APPROVED') {
            $values += ['status' => Payment::STATUS_COMPLETED, 'paid_at' => now(), 'failure_code' => null, 'failure_reason' => null];
        } elseif ($transaction->status === 'PENDING') {
            $values += ['status' => Payment::STATUS_PENDING, 'paid_at' => null];
        } else {
            $values += ['status' => Payment::STATUS_FAILED, 'paid_at' => null,
                'failure_code' => 'WOMPI_'.$transaction->status,
                // Fixed local vocabulary, never provider status_message (which may contain PII/secrets).
                'failure_reason' => match ($transaction->status) {
                    'DECLINED' => 'Pago rechazado por el proveedor.',
                    'ERROR' => 'El proveedor reportó un error en el pago.',
                    'VOIDED' => 'Pago anulado antes de su aprobación.',
                }];
        }
        $payment->fill($values);
        if ($payment->isDirty()) {
            $payment->save();
        }
        if ($transaction->status === 'APPROVED') {
            // Deliberately bypass generic partial-payment and order-completion workflows.
            $order->update(['payment_status' => Order::PAYMENT_PAID]);
        }
    }
}
