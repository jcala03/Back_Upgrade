<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Quotation;
use App\Support\Business\BusinessContext;

/** Explicit customer-facing projection. Never reads current product/customer values. */
class CommercialDocumentData
{
    public function __construct(private readonly BusinessSettingsService $settings) {}

    public function make(Quotation|Order $record): array
    {
        $quotation = $record instanceof Quotation;
        $record->load($quotation ? ['items', 'branch'] : ['items', 'branch', 'payments', 'charges']);
        $paid = $quotation ? 0 : (int) $record->payments->where('status', 'completed')->sum('amount');
        $confirmedPayment = ! $quotation && in_array($record->status, [Order::STATUS_CONFIRMED, Order::STATUS_COMPLETED], true)
            && $record->payment_status === Order::PAYMENT_PAID && $record->total > 0 && $paid >= $record->total;
        $kind = $quotation ? 'quotation' : ($confirmedPayment ? 'paid_sale' : ($record->status === Order::STATUS_PENDING ? 'pending_order' : 'order'));
        $prefix = $quotation ? 'COT' : ($confirmedPayment ? 'VENTA' : 'ORDEN');
        $code = sprintf('%s-%06d', $prefix, $record->id);
        $states = $quotation
            ? ['draft' => 'Borrador', 'sent' => 'Marcada como enviada', 'rejected' => 'Rechazada', 'expired' => 'Vencida', 'converted' => 'Convertida en venta']
            : ['pending' => 'Pendiente', 'confirmed' => 'Confirmada', 'completed' => 'Completada', 'cancelled' => 'Cancelada'];
        $paymentStates = ['unpaid' => 'Sin pagar', 'partial' => 'Pago parcial', 'paid' => 'Pagada', 'refunded' => 'Reembolsada'];

        return [
            'id' => $record->id,
            'kind' => $kind,
            'code' => $code,
            'filename' => $code.'.pdf',
            'number' => $quotation ? $record->quotation_number : $record->order_number,
            'title' => match ($kind) {
                'quotation' => 'Cotización',
                'paid_sale' => 'Comprobante de venta pagada',
                'pending_order' => 'Orden pendiente',
                default => $record->status === Order::STATUS_CANCELLED ? 'Orden cancelada' : 'Comprobante de orden',
            },
            'status' => $states[$record->status] ?? $record->status,
            'payment_status' => $quotation ? null : ($paymentStates[$record->payment_status] ?? $record->payment_status),
            'created_at' => $record->created_at?->timezone(BusinessContext::TIMEZONE)->format('d/m/Y H:i'),
            'valid_until' => $quotation ? $record->valid_until?->format('d/m/Y') : null,
            'business' => $this->settings->identity(),
            'branch' => $record->branch?->name,
            'customer' => [
                'name' => $record->customer_name ?: 'Cliente no identificado',
                'email' => $record->customer_email,
                'phone' => $record->customer_phone,
                'city' => $record->customer_city,
            ],
            'vehicle' => implode(' · ', array_filter([$record->vehicle_brand_name, $record->vehicle_model_name,
                $record->vehicle_version_name, $record->vehicle_year, $record->vehicle_plate])),
            'items' => $record->items->map(fn ($item) => [
                'name' => $item->item_type === 'service' ? ($item->service_name ?: 'Servicio') : ($item->product_name ?: 'Producto histórico'),
                'variant' => $item->variant_name,
                'sku' => $item->variant_sku ?: $item->product_sku,
                'configuration' => $this->configuration($item->configuration_context ?? null, $item->variant_specs),
                'quantity' => $item->quantity,
                'unit_price' => $item->unit_price,
                'discount' => $item->discount_amount,
                'total' => $item->total,
            ])->all(),
            'subtotal' => $record->subtotal,
            'discount_total' => $record->discount_total,
            'charges_total' => $quotation ? 0 : $record->charges_total,
            'charges' => $quotation ? [] : $record->charges->map(fn ($charge) => [
                'label' => $charge->label, 'amount' => $charge->amount,
            ])->all(),
            'total' => $record->total,
            'paid' => $quotation ? null : $paid,
            'balance' => $quotation ? null : max(0, $record->total - $paid),
            'currency' => $quotation ? BusinessContext::CURRENCY : ($record->currency ?: BusinessContext::CURRENCY),
            'notes' => $quotation ? $record->notes : null,
            'notice' => $quotation
                ? 'Cotización comercial. No acredita pago ni constituye una reserva de inventario.'
                : ($confirmedPayment ? 'Comprobante comercial de pago registrado. No es factura electrónica DIAN.'
                    : 'Este documento no acredita una venta pagada. Consulta los estados y abonos registrados. No es factura electrónica DIAN.'),
        ];
    }

    private function configuration(mixed $context, mixed $specs): array
    {
        $context = is_array($context) ? $context : [];
        $specs = is_array($specs) ? $specs : [];
        $labels = [];
        $vehicle = implode(' · ', array_filter(array_map(
            fn ($key) => is_string($context[$key] ?? null) ? $context[$key] : null,
            ['vehicle_brand_name', 'vehicle_model_name', 'vehicle_version_name'],
        )));
        if ($vehicle !== '') {
            $labels[] = 'Vehículo: '.$vehicle;
        }
        $oem = $context['vehicle_multimedia_system_name'] ?? $specs['vehicle_multimedia_system_name'] ?? $specs['sistema_oem'] ?? null;
        if (is_string($oem) && $oem !== '') {
            $labels[] = 'Sistema OEM: '.$oem;
        }
        foreach (['ram_gb' => 'RAM', 'almacenamiento_gb' => 'Almacenamiento'] as $key => $name) {
            $value = $context[$key] ?? $specs[$key] ?? null;
            if (is_numeric($value) && (float) $value > 0) {
                $labels[] = $name.': '.(0 + $value).' GB';
            }
        }
        if (! $oem && (isset($specs['ram_gb']) || isset($specs['almacenamiento_gb']))) {
            $labels[] = 'Sistema OEM: no consta en el registro histórico.';
        }

        return $labels;
    }

    public static function money(int $value, string $currency = 'COP'): string
    {
        return ($currency === 'COP' ? '$ ' : $currency.' ').number_format($value, 0, ',', '.');
    }
}
