<?php

namespace App\Services;

use App\Contracts\LandedCostProvider;
use App\Exceptions\EcommerceShippingException;
use App\Models\Branch;
use App\Models\Order;
use App\Models\OrderAddress;
use App\Models\OrderCharge;
use App\Support\LandedCost\LandedCostCharge;
use App\Support\LandedCost\LandedCostItemData;
use App\Support\LandedCost\LandedCostRequest;
use App\Support\LandedCost\LandedCostResult;
use App\Support\Shipping\ShippingAddressData;
use App\Support\Shipping\ShippingOriginData;
use App\Support\Shipping\ShippingQuoteResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class InternationalCheckoutCostService
{
    public function __construct(
        private readonly LandedCostProvider $provider,
        private readonly LogisticsSnapshotResolver $logistics,
        private readonly ShippingPackageBuilder $packages,
        private readonly OrderTotalService $totals,
    ) {}

    public function calculate(Order $order, ShippingQuoteResult $shippingQuote): ?LandedCostResult
    {
        $order->loadMissing(['branch', 'shippingAddress', 'items.product', 'items.productVariant.product', 'charges']);
        $this->assertPending($order);
        $this->assertSelectedShippingQuote($order, $shippingQuote);
        $origin = $this->origin($order->branch);
        $destination = $this->destination($order->shippingAddress);

        if ($origin->countryCode === $destination->countryCode) {
            return null;
        }

        $this->assertCustomsData($order);
        $packages = $this->packages->build($order, $origin, $destination);
        $items = array_map(fn ($package) => new LandedCostItemData(
            orderItemId: $package->orderItemId,
            name: $package->content,
            customsDescription: (string) $package->customsDescription,
            countryOfOrigin: Str::upper((string) $package->countryOfOrigin),
            hsCode: (string) $package->hsCode,
            quantity: $package->quantity,
            declaredUnitValue: $package->declaredUnitValue,
            weightGrams: $package->weightGrams,
            lengthMm: $package->lengthMm,
            widthMm: $package->widthMm,
            heightMm: $package->heightMm,
        ), $packages);

        $result = $this->provider->estimate(new LandedCostRequest(
            origin: $origin,
            destination: $destination,
            items: $items,
            shippingAmount: $shippingQuote->amount,
            insuranceAmount: (int) $order->charges()->where('type', OrderCharge::TYPE_INSURANCE)->sum('amount'),
            currency: $order->currency,
            carrier: $shippingQuote->carrier,
            serviceCode: $shippingQuote->serviceCode,
        ));

        if ($result->currency !== $order->currency) {
            throw new EcommerceShippingException(EcommerceShippingException::CURRENCY_MISMATCH, 'La moneda de landed cost no coincide con la de la orden.');
        }

        return $result->forShippingQuote($shippingQuote->quoteReference, $this->sanitizeMetadata($result->metadata));
    }

    public function apply(Order $order, ShippingQuoteResult $shippingQuote, LandedCostResult $result): Order
    {
        return DB::transaction(function () use ($order, $shippingQuote, $result) {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
            $locked->load(['branch', 'shippingAddress', 'charges']);
            $this->assertPending($locked);
            $this->assertSelectedShippingQuote($locked, $shippingQuote);

            if (! $result->estimated || $result->currency !== $locked->currency || $result->shippingQuoteReference !== $shippingQuote->quoteReference) {
                throw new EcommerceShippingException(EcommerceShippingException::INVALID_QUOTE, 'El resultado de landed cost no corresponde a la cotización de envío seleccionada.');
            }

            $locked->charges()->whereIn('type', [OrderCharge::TYPE_DUTY, OrderCharge::TYPE_TAX])->delete();
            foreach ($result->charges as $charge) {
                if (! $charge instanceof LandedCostCharge || ! in_array($charge->type, [LandedCostCharge::TYPE_DUTY, LandedCostCharge::TYPE_TAX], true)) {
                    continue;
                }
                $locked->charges()->create([
                    'type' => $charge->type === LandedCostCharge::TYPE_DUTY ? OrderCharge::TYPE_DUTY : OrderCharge::TYPE_TAX,
                    'label' => $charge->label,
                    'amount' => $charge->amount,
                    'currency' => $charge->currency,
                    'metadata' => $this->sanitizeMetadata([
                        ...$result->metadata,
                        'provider' => $result->provider,
                        'quote_reference' => $result->quoteReference,
                        'shipping_quote_reference' => $result->shippingQuoteReference,
                        'estimated' => $result->estimated,
                        'component_code' => $charge->code,
                    ]),
                ]);
            }

            return $this->totals->recalculate($locked)->load('charges');
        });
    }

    private function assertCustomsData(Order $order): void
    {
        foreach ($order->items as $item) {
            $package = $this->logistics->resolve($item);
            if (! $package) {
                continue;
            }
            $missing = collect([
                'country_of_origin' => $package->countryOfOrigin,
                'hs_code' => $package->hsCode,
                'customs_description' => $package->customsDescription,
            ])->filter(fn ($value) => blank($value))->keys()->all();
            if ($missing !== []) {
                throw new EcommerceShippingException(
                    EcommerceShippingException::MISSING_CUSTOMS_DATA,
                    'Faltan datos aduaneros obligatorios para calcular landed cost.',
                    ['order_item_id' => $package->orderItemId, 'missing_fields' => $missing],
                );
            }
        }
    }

    private function assertSelectedShippingQuote(Order $order, ShippingQuoteResult $quote): void
    {
        if ($quote->isExpired() || $quote->currency !== $order->currency || ! $quote->branchId || $quote->branchId !== $order->branch_id) {
            throw new EcommerceShippingException(EcommerceShippingException::INVALID_QUOTE, 'La cotización de envío seleccionada no es válida para la orden.');
        }
        $selected = $order->charges->first(fn (OrderCharge $charge) => $charge->type === OrderCharge::TYPE_SHIPPING
            && ($charge->metadata['quote_reference'] ?? null) === $quote->quoteReference);
        if (! $selected) {
            throw new EcommerceShippingException(EcommerceShippingException::INVALID_QUOTE, 'La cotización de envío no está aplicada actualmente a la orden.');
        }
    }

    private function origin(?Branch $branch): ShippingOriginData
    {
        if (! $branch) {
            throw new EcommerceShippingException(EcommerceShippingException::MISSING_ORIGIN_DATA, 'La orden no tiene una sede de fulfillment seleccionada.');
        }
        $missing = collect(['country_code', 'state', 'city', 'postal_code', 'address_line1'])
            ->filter(fn (string $field) => blank($branch->{$field}))->values()->all();
        if ($missing !== [] || ! preg_match('/^[A-Z]{2}$/', Str::upper($branch->country_code))) {
            throw new EcommerceShippingException(EcommerceShippingException::MISSING_ORIGIN_DATA, 'La sede seleccionada no tiene configurado un origen logístico completo.', ['branch_id' => $branch->id, 'missing_fields' => $missing]);
        }

        return new ShippingOriginData($branch->id, $branch->code, Str::upper($branch->country_code), $branch->state, $branch->city, $branch->postal_code, $branch->address_line1, $branch->address_line2);
    }

    private function destination(?OrderAddress $address): ShippingAddressData
    {
        if (! $address || ! preg_match('/^[A-Z]{2}$/', Str::upper((string) $address->country_code))) {
            throw new EcommerceShippingException(EcommerceShippingException::UNSUPPORTED_DESTINATION, 'La dirección internacional de destino no es válida.');
        }

        return new ShippingAddressData($address->recipient_name, $address->recipient_phone, Str::upper($address->country_code), $address->state, $address->city, $address->postal_code, $address->address_line1, $address->address_line2, $address->delivery_notes);
    }

    private function assertPending(Order $order): void
    {
        if ($order->status !== Order::STATUS_PENDING) {
            throw new EcommerceShippingException(EcommerceShippingException::ORDER_NOT_PENDING, 'Solo una orden pendiente puede calcular o aplicar landed cost.');
        }
    }

    private function sanitizeMetadata(array $metadata): array
    {
        $sanitized = [];
        foreach ($metadata as $key => $value) {
            if (is_string($key) && preg_match('/token|secret|password|credential|api.?key|authorization/i', $key)) {
                continue;
            }
            $sanitized[$key] = is_array($value) ? $this->sanitizeMetadata($value) : $value;
        }

        return $sanitized;
    }
}
