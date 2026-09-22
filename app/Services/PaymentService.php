<?php

namespace App\Services;

use App\Models\CrmNotification;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class PaymentService
{
    public function __construct(
        private readonly CrmNotificationService $notifications,
        private readonly CommercialEmployeeContext $commercialContext,
    ) {}

    public function register(Order $order, array $data, ?User $user = null): Payment
    {
        [$payment, $lockedOrder] = DB::transaction(function () use ($order, $data, $user) {
            $lockedOrder = Order::query()->lockForUpdate()->findOrFail($order->id);

            return [$this->persistLocked($lockedOrder, $data, $user), $lockedOrder];
        });

        $this->notifyCompleted($payment, $lockedOrder);

        return $payment;
    }

    public function registerOwn(Order $order, array $data, User $user): Payment
    {
        [$payment, $lockedOrder] = DB::transaction(function () use ($order, $data, $user) {
            $context = $this->commercialContext->resolve($user);
            $lockedOrder = Order::query()
                ->whereKey($order->id)
                ->where('sales_employee_id', $context['employee']->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedOrder->branch_id === null) {
                throw ValidationException::withMessages([
                    'branch_id' => 'La venta no tiene una sede asociada.',
                ]);
            }
            if ($lockedOrder->status === Order::STATUS_CANCELLED) {
                throw ValidationException::withMessages([
                    'order' => 'No puedes registrar pagos sobre una venta cancelada.',
                ]);
            }

            $lockedContext = $this->commercialContext->resolveForBranch(
                $user,
                (int) $lockedOrder->branch_id,
                true,
            );
            if ($lockedContext['employee']->id !== $context['employee']->id) {
                throw (new ModelNotFoundException)->setModel(Order::class, [$order->id]);
            }
            $data['status'] = Payment::STATUS_COMPLETED;

            return [$this->persistLocked($lockedOrder, $data, $user), $lockedOrder];
        });

        $this->notifyCompleted($payment, $lockedOrder);

        return $payment;
    }

    public function recalculate(Order $order): void
    {
        $paid = (int) $order->payments()->where('status', Payment::STATUS_COMPLETED)->sum('amount');
        $status = $paid <= 0 ? Order::PAYMENT_UNPAID : ($paid < $order->total ? Order::PAYMENT_PARTIAL : Order::PAYMENT_PAID);
        $order->update(['payment_status' => $status]);
    }

    private function persistLocked(Order $order, array $data, ?User $user): Payment
    {
        $status = $data['status'] ?? Payment::STATUS_COMPLETED;
        $completed = (int) $order->payments()
            ->where('status', Payment::STATUS_COMPLETED)
            ->sum('amount');
        if ($status === Payment::STATUS_COMPLETED && $completed + (int) $data['amount'] > $order->total) {
            throw ValidationException::withMessages([
                'amount' => 'El pago supera el saldo pendiente de la orden.',
            ]);
        }

        $payment = $order->payments()->create([
            ...$data,
            'status' => $status,
            'paid_at' => $status === Payment::STATUS_COMPLETED
                ? ($data['paid_at'] ?? now())
                : ($data['paid_at'] ?? null),
            'created_by' => $user?->id,
        ]);
        $this->recalculate($order);

        return $payment;
    }

    private function notifyCompleted(Payment $payment, Order $order): void
    {
        if ($payment->status !== Payment::STATUS_COMPLETED) {
            return;
        }

        try {
            $this->notifications->distribute('payments.view', [
                'type' => CrmNotification::TYPE_PAYMENT_RECEIVED,
                'severity' => CrmNotification::SEVERITY_SUCCESS,
                'title' => 'Pago recibido',
                'message' => "Se registró un pago para la orden {$order->order_number}.",
                'data' => [
                    'payment_id' => $payment->id,
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'amount' => $payment->amount,
                    'method' => $payment->method,
                ],
                'reference_type' => 'payment',
                'reference_id' => $payment->id,
                'dedupe_key' => "payment_received:{$payment->id}",
            ]);
        } catch (\Throwable $exception) {
            Log::warning('No se pudo crear la notificación CRM de pago.', [
                'payment_id' => $payment->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
