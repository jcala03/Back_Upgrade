<?php

namespace App\Console\Commands;

use App\Models\CrmNotification;
use App\Models\Quotation;
use App\Services\CrmNotificationService;
use App\Support\Business\BusinessContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class NotifyExpiringQuotations extends Command
{
    protected $signature = 'crm:notify-expiring-quotations';

    protected $description = 'Crea alertas CRM para las cotizaciones vigentes que vencen mañana';

    public function handle(CrmNotificationService $notifications): int
    {
        $tomorrow = CarbonImmutable::now(BusinessContext::TIMEZONE)->addDay()->toDateString();
        $distributed = 0;

        Quotation::query()
            ->whereIn('status', [Quotation::STATUS_DRAFT, Quotation::STATUS_SENT])
            ->whereNull('order_id')
            ->whereDate('valid_until', $tomorrow)
            ->orderBy('id')
            ->eachById(function (Quotation $quotation) use ($notifications, $tomorrow, &$distributed) {
                try {
                    $distributed += $notifications->distribute('quotations.view', [
                        'type' => CrmNotification::TYPE_QUOTATION_EXPIRING,
                        'severity' => CrmNotification::SEVERITY_WARNING,
                        'title' => 'Cotización próxima a vencer',
                        'message' => "La cotización {$quotation->quotation_number} vence mañana.",
                        'data' => [
                            'quotation_id' => $quotation->id,
                            'quotation_number' => $quotation->quotation_number,
                            'valid_until' => $tomorrow,
                        ],
                        'reference_type' => 'quotation',
                        'reference_id' => $quotation->id,
                        'dedupe_key' => "quotation_expiring:{$quotation->id}:{$tomorrow}",
                    ]);
                } catch (\Throwable $exception) {
                    Log::warning('No se pudo crear la alerta CRM de cotización próxima a vencer.', [
                        'quotation_id' => $quotation->id,
                        'error' => $exception->getMessage(),
                    ]);
                }
            });

        $this->info("Notificaciones distribuidas: {$distributed}");

        return self::SUCCESS;
    }
}
