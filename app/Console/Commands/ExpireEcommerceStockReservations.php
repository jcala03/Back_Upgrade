<?php

namespace App\Console\Commands;

use App\Services\EcommerceStockReservationExpiryService;
use Illuminate\Console\Command;

class ExpireEcommerceStockReservations extends Command
{
    protected $signature = 'ecommerce:expire-stock-reservations';

    protected $description = 'Cancela de forma segura las reservas ecommerce de stock vencidas.';

    public function handle(EcommerceStockReservationExpiryService $expiry): int
    {
        $counts = array_fill_keys([
            EcommerceStockReservationExpiryService::OUTCOME_EXPIRED,
            EcommerceStockReservationExpiryService::OUTCOME_PAID,
            EcommerceStockReservationExpiryService::OUTCOME_RECONCILIATION,
            EcommerceStockReservationExpiryService::OUTCOME_SKIPPED,
        ], 0);

        foreach ($expiry->candidateIds() as $orderId) {
            $counts[$expiry->expire($orderId)]++;
        }

        $this->info(sprintf(
            'Reservas procesadas: expiradas=%d, pagadas=%d, conciliacion=%d, omitidas=%d.',
            $counts[EcommerceStockReservationExpiryService::OUTCOME_EXPIRED],
            $counts[EcommerceStockReservationExpiryService::OUTCOME_PAID],
            $counts[EcommerceStockReservationExpiryService::OUTCOME_RECONCILIATION],
            $counts[EcommerceStockReservationExpiryService::OUTCOME_SKIPPED],
        ));

        return self::SUCCESS;
    }
}
