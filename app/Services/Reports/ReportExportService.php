<?php

namespace App\Services\Reports;

use App\Models\BusinessSetting;
use App\Models\Quotation;
use App\Models\User;
use App\Support\Business\BusinessContext;
use App\Support\Reports\ReportPeriod;
use App\Support\Reports\ReportScope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class ReportExportService
{
    public const MIME = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    public function __construct(
        private readonly SalesReportService $sales,
        private readonly PaymentReportService $payments,
        private readonly InventoryReportService $inventory,
        private readonly QuotationReportService $quotations,
        private readonly CustomerReportService $customers,
    ) {}

    public function download(string $report, array $filters, User $user): BinaryFileResponse
    {
        unset($filters['page'], $filters['per_page']);
        $definition = $this->definition($report, $filters, $user);
        $directory = storage_path('app/private/report-exports');
        File::ensureDirectoryExists($directory);
        $path = $directory.'/'.Str::uuid().'.xlsx';
        $options = new Options;
        $options->setColumnWidth(18, ...range(1, count($definition['columns'])));
        $writer = new Writer($options);

        try {
            $writer->openToFile($path);
            $writer->getCurrentSheet()->setName($this->sheetName($report));
            $titleStyle = (new Style)->setFontBold()->setFontSize(14);
            $headerStyle = (new Style)->setFontBold();
            $branch = ReportScope::branch($filters);
            $period = $definition['period'];
            $writer->addRow(Row::fromValues(['Empresa', $this->businessName()], $titleStyle));
            $writer->addRow(Row::fromValues(['Reporte', $definition['title']]));
            $writer->addRow(Row::fromValues(['Sede', $branch?->name ?? 'General']));
            $writer->addRow(Row::fromValues(['Período', $period]));
            $writer->addRow(Row::fromValues(['Generado en', BusinessContext::now()->format('Y-m-d H:i:s').' '.BusinessContext::TIMEZONE]));
            $writer->addRow(Row::fromValues([]));
            $writer->addRow(Row::fromValues(array_column($definition['columns'], 'header'), $headerStyle));

            foreach ($definition['query']->cursor() as $record) {
                $values = [];
                foreach ($definition['columns'] as $column) {
                    $values[] = $this->safeCell(($column['value'])($record));
                }
                $writer->addRow(Row::fromValues($values));
            }
            $writer->close();
        } catch (Throwable $exception) {
            try {
                $writer->close();
            } catch (Throwable) {
            }
            File::delete($path);
            throw $exception;
        }

        return response()->download($path, $definition['filename'], ['Content-Type' => self::MIME])->deleteFileAfterSend(true);
    }

    public function neutralizeFormulaValue(mixed $value): mixed
    {
        return $this->safeCell($value);
    }

    private function definition(string $report, array $filters, User $user): array
    {
        $financial = $user->hasPermission('reports.financials');
        $branchName = ReportScope::branch($filters)?->name ?? 'General';
        $period = $report === 'inventory'
            ? BusinessContext::today()->toDateString()
            : $this->periodLabel($filters);

        return match ($report) {
            'sales' => [
                'title' => 'Ventas', 'period' => $period, 'filename' => $this->filename('ventas', $filters),
                'query' => $this->sales->salesExportQuery($filters),
                'columns' => [
                    $this->column('Venta', 'order_number'), $this->column('Fecha confirmada', fn ($row) => $this->dateTime($row->confirmed_at)),
                    $this->column('Sede', 'branch_name'), $this->column('Vendedor', fn ($row) => $row->seller_name ?? $row->operator_name),
                    $this->column('Cliente', fn ($row) => $row->customer_name ?: 'Venta mostrador'), $this->column('Origen', 'origin'),
                    $this->column('Estado', 'status'), $this->column('Estado de pago', 'payment_status'),
                    $this->column('Subtotal', fn ($row) => (int) $row->subtotal), $this->column('Descuento', fn ($row) => (int) $row->discount_total),
                    $this->column('Total', fn ($row) => (int) $row->total),
                    ...($financial ? [$this->column('Costo conocido', fn ($row) => (int) $row->cogs), $this->column('Utilidad sobre costo conocido', fn ($row) => (int) $row->known_cost_revenue - (int) $row->cogs)] : []),
                ],
            ],
            'products' => [
                'title' => 'Productos', 'period' => $period, 'filename' => $this->filename('productos', $filters),
                'query' => $this->sales->productsExportQuery($filters),
                'columns' => [
                    $this->column('Sede', fn () => $branchName),
                    $this->column('Producto', 'product_name'), $this->column('SKU', fn ($row) => $row->variant_sku ?: $row->product_sku),
                    $this->column('Variante', 'variant_name'), $this->column('Unidades vendidas', fn ($row) => (int) $row->quantity),
                    $this->column('Órdenes', fn ($row) => (int) $row->orders_count), $this->column('Ventas', fn ($row) => (int) $row->revenue),
                    $this->column('Stock', fn ($row) => (int) $row->stock),
                    ...($financial ? [$this->column('COGS', fn ($row) => (int) $row->cogs), $this->column('Utilidad conocida', fn ($row) => (int) $row->known_cost_revenue - (int) $row->cogs)] : []),
                ],
            ],
            'payments' => [
                'title' => 'Pagos', 'period' => $period, 'filename' => $this->filename('pagos', $filters),
                'query' => $this->payments->paymentsExportQuery($filters),
                'columns' => [
                    $this->column('Pago', fn ($row) => (int) $row->id), $this->column('Orden', 'order_number'), $this->column('Sede', 'branch_name'),
                    $this->column('Fecha de pago', fn ($row) => $this->dateTime($row->paid_at)), $this->column('Método', 'method'),
                    $this->column('Estado', 'status'), $this->column('Monto', fn ($row) => (int) $row->amount), $this->column('Referencia', 'reference'),
                ],
            ],
            'receivables' => [
                'title' => 'Cartera', 'period' => $period, 'filename' => $this->filename('cartera', $filters),
                'query' => $this->payments->receivablesExportQuery($filters),
                'columns' => [
                    $this->column('Orden', 'order_number'), $this->column('Sede', 'branch_name'),
                    $this->column('Cliente', fn ($row) => $row->customer_name ?: 'Venta mostrador'),
                    $this->column('Fecha confirmada', fn ($row) => $this->dateTime($row->confirmed_at)),
                    $this->column('Total orden', fn ($row) => (int) $row->total), $this->column('Pagado', fn ($row) => (int) $row->paid),
                    $this->column('Saldo', fn ($row) => (int) $row->outstanding), $this->column('Estado de pago', 'payment_status'),
                    $this->column('Días de cartera', fn ($row) => $this->daysOutstanding($row->confirmed_at)),
                ],
            ],
            'inventory' => [
                'title' => 'Inventario', 'period' => $period, 'filename' => $this->filename('inventario', $filters, false),
                'query' => $this->inventory->inventoryExportQuery($filters),
                'columns' => [
                    $this->column('Sede', 'branch_name'), $this->column('Producto', 'name'), $this->column('Variante', 'variant_name'),
                    $this->column('SKU', 'sku'), $this->column('Cantidad', fn ($row) => (int) $row->stock),
                    $this->column('Cantidad mínima', fn ($row) => (int) $row->minimum_stock), $this->column('Estado', 'status'),
                    ...($financial ? [$this->column('Costo unitario actual', fn ($row) => (int) $row->current_cost)] : []),
                ],
            ],
            'inventory-movements' => [
                'title' => 'Movimientos de inventario', 'period' => $period, 'filename' => $this->filename('movimientos_inventario', $filters),
                'query' => $this->inventory->movementsExportQuery($filters),
                'columns' => [
                    $this->column('Fecha', fn ($row) => $this->dateTime($row->created_at)), $this->column('Sede', 'branch_name'),
                    $this->column('Producto', 'product_name'), $this->column('Variante', 'variant_name'), $this->column('SKU', 'sku'),
                    $this->column('Tipo', 'type'), $this->column('Cambio', fn ($row) => (int) $row->quantity_delta),
                    $this->column('Saldo anterior', fn ($row) => (int) $row->stock_before), $this->column('Saldo posterior', fn ($row) => (int) $row->stock_after),
                    $this->column('Referencia', fn ($row) => trim(($row->reference_type ?? '').' '.($row->reference_id ?? ''))),
                    $this->column('Motivo', 'reason'),
                ],
            ],
            'quotations' => [
                'title' => 'Cotizaciones', 'period' => $period, 'filename' => $this->filename('cotizaciones', $filters),
                'query' => $this->quotations->exportQuery($filters),
                'columns' => [
                    $this->column('Cotización', 'quotation_number'), $this->column('Fecha', fn ($row) => $this->dateTime($row->created_at)),
                    $this->column('Válida hasta', fn ($row) => $row->valid_until ? CarbonImmutable::parse($row->valid_until)->toDateString() : null),
                    $this->column('Sede', 'branch_name'), $this->column('Vendedor', fn ($row) => $row->seller_name ?? $row->operator_name),
                    $this->column('Cliente', fn ($row) => $row->customer_name ?: 'Cotización mostrador'),
                    $this->column('Estado', fn ($row) => $this->effectiveQuotationStatus($row)),
                    $this->column('Subtotal', fn ($row) => (int) $row->subtotal), $this->column('Descuento', fn ($row) => (int) $row->discount_total),
                    $this->column('Total', fn ($row) => (int) $row->total), $this->column('Orden convertida', 'order_number'),
                ],
            ],
            'customers' => [
                'title' => 'Clientes', 'period' => $period, 'filename' => $this->filename('clientes', $filters),
                'query' => $this->customers->exportQuery($filters),
                'columns' => [
                    $this->column('Sede', fn () => $branchName),
                    $this->column('Cliente', 'name'),
                    $this->column('Activo', fn ($row) => (bool) $row->is_active ? 'Sí' : 'No'),
                    $this->column('Ventas', fn ($row) => (int) $row->orders_count), $this->column('Total vendido', fn ($row) => (int) $row->total_spent),
                    $this->column('Última venta', fn ($row) => $row->last_purchase_at ? $this->dateTime($row->last_purchase_at) : null),
                ],
            ],
            default => throw new \InvalidArgumentException('Reporte no soportado.'),
        };
    }

    private function column(string $header, string|callable $value): array
    {
        return ['header' => $header, 'value' => is_string($value) ? fn ($row) => $row->{$value} : $value];
    }

    private function safeCell(mixed $value): null|bool|float|int|string
    {
        if ($value === null || is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }
        $text = (string) $value;

        return preg_match('/^[=+\-@]/u', $text) === 1 ? "'".$text : $text;
    }

    private function businessName(): string
    {
        return BusinessSetting::query()->value('business_name') ?: 'Upgrade La 79';
    }

    private function periodLabel(array $filters): string
    {
        if (! isset($filters['date_from'], $filters['date_to']) && array_key_exists('date_from', $filters) === false && array_key_exists('date_to', $filters) === false) {
            $period = ReportPeriod::from($filters);

            return $period->dateFrom.' - '.$period->dateTo;
        }
        if (isset($filters['date_from'], $filters['date_to'])) {
            return $filters['date_from'].' - '.$filters['date_to'];
        }

        return 'Histórico completo';
    }

    private function filename(string $prefix, array $filters, bool $periodRange = true): string
    {
        $branch = ReportScope::branch($filters);
        $scope = $branch ? Str::slug($branch->name, '_') : 'general';
        $period = $periodRange
            ? (isset($filters['date_from'], $filters['date_to']) ? $filters['date_from'].'_'.$filters['date_to'] : ReportPeriod::from($filters)->dateFrom.'_'.ReportPeriod::from($filters)->dateTo)
            : BusinessContext::today()->toDateString();

        return preg_replace('/[^a-z0-9_-]+/', '', Str::ascii(strtolower("{$prefix}_{$scope}_{$period}"))).'.xlsx';
    }

    private function dateTime(mixed $value): ?string
    {
        return $value ? CarbonImmutable::parse($value)->setTimezone(BusinessContext::TIMEZONE)->format('Y-m-d H:i:s') : null;
    }

    private function sheetName(string $report): string
    {
        return match ($report) {
            'sales' => 'Ventas',
            'products' => 'Productos',
            'payments' => 'Pagos',
            'receivables' => 'Cartera',
            'inventory' => 'Inventario',
            'inventory-movements' => 'Movimientos',
            'quotations' => 'Cotizaciones',
            'customers' => 'Clientes',
            default => throw new \InvalidArgumentException('Reporte no soportado.'),
        };
    }

    private function daysOutstanding(mixed $confirmedAt): int
    {
        return (int) CarbonImmutable::parse($confirmedAt)->setTimezone(BusinessContext::TIMEZONE)->startOfDay()->diffInDays(BusinessContext::today());
    }

    private function effectiveQuotationStatus(object $row): string
    {
        return in_array($row->status, [Quotation::STATUS_DRAFT, Quotation::STATUS_SENT], true)
            && $row->valid_until !== null
            && CarbonImmutable::parse($row->valid_until)->toDateString() < BusinessContext::today()->toDateString()
                ? Quotation::STATUS_EXPIRED
                : $row->status;
    }
}
