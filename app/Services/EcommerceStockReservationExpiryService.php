<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Payment;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class EcommerceStockReservationExpiryService
{
    public const OUTCOME_EXPIRED = 'expired';

    public const OUTCOME_PAID = 'paid';

    public const OUTCOME_RECONCILIATION = 'reconciliation';

    public const OUTCOME_SKIPPED = 'skipped';

    public function __construct(private readonly OrderService $orders) {}

    /**
     * The candidate query is deliberately only a cheap first pass. Every business
     * decision is repeated below while locks are held.
     */
    public function candidateIds(): array
    {
        return Order::query()
            ->where('origin', Order::ORIGIN_ECOMMERCE)
            ->where('status', Order::STATUS_CONFIRMED)
            ->whereNotNull('stock_reservation_expires_at')
            ->where('stock_reservation_expires_at', '<=', now())
            ->orderBy('id')
            ->pluck('id')
            ->all();
    }

    /**
     * Lock order matches the financial webhook after its ledger lock: Payment ->
     * Order. This worker has no WebhookEvent row to lock.
     */
    public function expire(int $orderId): string
    {
        return DB::transaction(function () use ($orderId): string {
            $payments = Payment::query()
                ->where('order_id', $orderId)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $order = Order::query()->whereKey($orderId)->lockForUpdate()->first();

            if (! $order
                || $order->origin !== Order::ORIGIN_ECOMMERCE
                || $order->status !== Order::STATUS_CONFIRMED
                || $order->stock_reservation_expires_at === null
                || $order->stock_reservation_expires_at->isFuture()
                || $order->stock_reverted_at !== null) {
                return self::OUTCOME_SKIPPED;
            }

            if ($payments->contains(fn (Payment $payment): bool => $payment->reconciliation_required_at !== null)) {
                return self::OUTCOME_RECONCILIATION;
            }

            if ($this->hasSufficientValidCredit($order, $payments)) {
                // A valid full payment consumes the reservation rather than extending it.
                $values = ['stock_reservation_expires_at' => null];
                if ($order->payment_status !== Order::PAYMENT_PAID) {
                    $values['payment_status'] = Order::PAYMENT_PAID;
                }
                $order->update($values);

                return self::OUTCOME_PAID;
            }

            // OrderService owns cancellation, sale reversal, commission voiding and
            // their exactly-once sentinels. Its nested transaction is a savepoint.
            $this->orders->transition($order, Order::STATUS_CANCELLED, null, 'Reserva ecommerce vencida');

            return self::OUTCOME_EXPIRED;
        }, 3);
    }

    /** @param Collection<int, Payment> $payments */
    private function hasSufficientValidCredit(Order $order, $payments): bool
    {
        $remaining = max(0, (int) $order->total);
        foreach ($payments as $payment) {
            if ($payment->status !== Payment::STATUS_COMPLETED
                || $payment->reconciliation_required_at !== null
                || $payment->currency !== $order->currency
                || $payment->amount <= 0) {
                continue;
            }
            $remaining -= min($remaining, $payment->amount);
        }

        return $remaining === 0;
    }
}
