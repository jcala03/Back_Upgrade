<?php

namespace App\Services;

use App\Models\Order;
use Illuminate\Support\Facades\DB;

class OrderTotalService
{
    public function calculate(int $subtotal, int $discountTotal, int $chargesTotal): int
    {
        return $subtotal - $discountTotal + $chargesTotal;
    }

    public function recalculate(Order $order): Order
    {
        return DB::transaction(function () use ($order) {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
            $chargesTotal = (int) $locked->charges()->sum('amount');

            $locked->update([
                'charges_total' => $chargesTotal,
                'total' => $this->calculate(
                    (int) $locked->subtotal,
                    (int) $locked->discount_total,
                    $chargesTotal,
                ),
            ]);

            return $locked->refresh();
        });
    }
}
