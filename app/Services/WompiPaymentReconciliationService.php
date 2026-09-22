<?php

namespace App\Services;

use App\Models\CrmNotification;
use App\Models\Order;
use App\Models\Payment;
use App\Support\Payments\WompiTransaction;

class WompiPaymentReconciliationService
{
    public const LATE_APPROVAL = 'LATE_APPROVAL';

    public const DUPLICATE_APPROVAL = 'DUPLICATE_APPROVAL';

    public const VOID_AFTER_APPROVAL = 'VOID_AFTER_APPROVAL';

    public const TRANSACTION_CONFLICT = 'TRANSACTION_CONFLICT';

    public function __construct(private readonly CrmNotificationService $notifications) {}

    /** Called only inside the webhook transaction, with Payment/Order already locked. */
    public function mark(Payment $payment, string $reason): void
    {
        if ($payment->reconciliation_required_at === null) {
            $payment->reconciliation_required_at = now();
        }
        if ($payment->reconciliation_reason === null) {
            $payment->reconciliation_reason = $reason;
        }
        if ($payment->isDirty()) {
            $payment->save();
        }
        // Transactional DB notification: failure rolls back the whole event for redelivery.
        $this->notifications->distribute('payments.view', [
            'type' => CrmNotification::TYPE_PAYMENT_RECONCILIATION_REQUIRED,
            'severity' => CrmNotification::SEVERITY_DANGER,
            'title' => 'Pago requiere revisión manual',
            'message' => 'Se detectó una inconsistencia financiera en un pago ecommerce.',
            'data' => ['payment_id' => $payment->id, 'order_id' => $payment->order_id, 'reason' => $reason],
            'reference_type' => 'payment', 'reference_id' => $payment->id,
            'dedupe_key' => 'wompi_reconciliation:'.$payment->id.':'.$reason,
        ]);
    }

    public function approvedForReview(Payment $payment, WompiTransaction $transaction, string $reason): void
    {
        // Pending manual allocation, NOT provider-declined and NOT valid order credit.
        $payment->fill(['status' => Payment::STATUS_PENDING, 'provider_status' => 'APPROVED',
            'transaction_id' => $transaction->transactionId, 'paid_at' => null,
            'failure_code' => null, 'failure_reason' => null]);
        $this->mark($payment, $reason);
    }

    public function voidAfterApproval(Payment $payment, Order $order, WompiTransaction $transaction): void
    {
        $payment->fill(['status' => Payment::STATUS_REFUNDED, 'provider_status' => 'VOIDED', 'transaction_id' => $transaction->transactionId]);
        // Preserve historical paid_at; refunded means withdrawn credit, not an API refund request.
        $this->mark($payment, self::VOID_AFTER_APPROVAL);
        $total = (int) $order->total;
        $remaining = max(0, $total);
        $hasCredit = false;
        foreach ($order->payments()->where('status', Payment::STATUS_COMPLETED)
            ->whereNull('reconciliation_required_at')->where('currency', $order->currency)->get() as $credit) {
            if ($credit->currency !== $order->currency || $credit->amount <= 0) {
                continue;
            }
            $hasCredit = true;
            // Cap at order total, avoiding addition overflow for multiple BIGINT amounts.
            $remaining -= min($remaining, $credit->amount);
        }
        $status = ! $hasCredit ? Order::PAYMENT_UNPAID : ($remaining === 0 ? Order::PAYMENT_PAID : Order::PAYMENT_PARTIAL);
        if ($order->payment_status !== $status) {
            $order->update(['payment_status' => $status]);
        }
    }
}
