<?php

namespace App\Services;

use App\Contracts\ShippingQuoteProvider;
use App\Exceptions\EcommerceShippingException;
use App\Models\Branch;
use App\Models\Order;
use App\Models\OrderAddress;
use App\Models\OrderCharge;
use App\Support\Shipping\ShippingAddressData;
use App\Support\Shipping\ShippingOriginData;
use App\Support\Shipping\ShippingQuoteRequest;
use App\Support\Shipping\ShippingQuoteResult;
use Illuminate\Support\Facades\DB;

class ShippingQuoteService
{
    public function __construct(
        private readonly ShippingQuoteProvider $provider,
        private readonly EcommerceFulfillmentBranchResolver $fulfillment,
        private readonly ShippingPackageBuilder $packages,
        private readonly OrderTotalService $totals,
    ) {}

    /** @return list<ShippingQuoteResult> */
    public function quote(Order $order): array
    {
        $order->loadMissing(['shippingAddress', 'items.product', 'items.productVariant.product']);
        $destination = $this->destination($order->shippingAddress);
        $branch = $order->branch_id
            ? $this->existingBranch($order)
            : $this->fulfillment->select($order);
        $origin = $this->origin($branch);
        $request = new ShippingQuoteRequest(
            origin: $origin,
            destination: $destination,
            packages: $this->packages->build($order, $origin, $destination),
            currency: $order->currency,
        );

        $quotes = collect($this->provider->quote($request))
            ->filter(fn ($quote) => $quote instanceof ShippingQuoteResult)
            ->filter(fn (ShippingQuoteResult $quote) => $this->validQuote($quote, $order->currency))
            ->map(fn (ShippingQuoteResult $quote) => $quote->forBranch(
                $branch->id,
                $this->sanitizeMetadata($quote->metadata),
            ))
            ->values()
            ->all();

        if ($quotes === []) {
            throw new EcommerceShippingException(
                EcommerceShippingException::NO_QUOTES_AVAILABLE,
                'No hay opciones de envío disponibles para esta orden y destino.',
                ['order_id' => $order->id],
            );
        }

        return $quotes;
    }

    public function apply(Order $order, ShippingQuoteResult $quote): Order
    {
        return DB::transaction(function () use ($order, $quote) {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
            $this->assertPending($locked);

            if ($quote->isExpired()) {
                throw new EcommerceShippingException(
                    EcommerceShippingException::QUOTE_EXPIRED,
                    'La cotización de envío seleccionada expiró.',
                    ['quote_reference' => $quote->quoteReference],
                );
            }

            if (! $quote->branchId || ! $this->validQuote($quote, $locked->currency)) {
                throw new EcommerceShippingException(
                    EcommerceShippingException::INVALID_QUOTE,
                    'La cotización de envío seleccionada no es válida para esta orden.',
                    ['quote_reference' => $quote->quoteReference],
                );
            }

            if (! $locked->shippingAddress()->where('type', OrderAddress::TYPE_SHIPPING)->exists()) {
                throw new EcommerceShippingException(
                    EcommerceShippingException::MISSING_SHIPPING_ADDRESS,
                    'La orden no tiene una dirección de envío estructurada.',
                    ['order_id' => $locked->id],
                );
            }

            $branch = Branch::query()->find($quote->branchId);
            if (! $branch) {
                throw new EcommerceShippingException(
                    EcommerceShippingException::STALE_STOCK,
                    'La sede cotizada ya no está disponible.',
                    ['branch_id' => $quote->branchId],
                );
            }
            $branch = $this->fulfillment->revalidateUnderLock($locked, $branch);

            $locked->update([
                'branch_id' => $branch->id,
                'fulfillment_type' => Order::FULFILLMENT_SHIPPING,
            ]);

            $charges = $locked->charges()
                ->where('type', OrderCharge::TYPE_SHIPPING)
                ->lockForUpdate()
                ->orderBy('id')
                ->get();
            $charge = $charges->shift() ?? new OrderCharge(['order_id' => $locked->id]);
            $charge->fill([
                'type' => OrderCharge::TYPE_SHIPPING,
                'label' => 'Envío - '.$quote->serviceName,
                'amount' => $quote->amount,
                'currency' => $quote->currency,
                'metadata' => $this->quoteMetadata($quote),
            ]);
            $charge->save();
            $charges->each->delete();

            return $this->totals->recalculate($locked)->load(['charges', 'shippingAddress', 'branch']);
        });
    }

    public function applyPickup(Order $order, Branch $branch): Order
    {
        return DB::transaction(function () use ($order, $branch) {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
            $this->assertPending($locked);
            $branch = $this->fulfillment->revalidateUnderLock($locked, $branch);

            $locked->update([
                'branch_id' => $branch->id,
                'fulfillment_type' => Order::FULFILLMENT_PICKUP,
            ]);
            $locked->charges()->where('type', OrderCharge::TYPE_SHIPPING)->delete();

            return $this->totals->recalculate($locked)->load(['charges', 'branch']);
        });
    }

    private function existingBranch(Order $order): Branch
    {
        $branch = Branch::query()->find($order->branch_id);
        if (! $branch || ! $this->fulfillment->candidates($order)->contains('id', $branch->id)) {
            throw new EcommerceShippingException(
                EcommerceShippingException::NO_BRANCH_CAN_FULFILL,
                'La sede asignada no puede surtir todos los productos de la orden.',
                ['order_id' => $order->id, 'branch_id' => $order->branch_id],
            );
        }

        return $branch;
    }

    private function destination(?OrderAddress $address): ShippingAddressData
    {
        if (! $address) {
            throw new EcommerceShippingException(
                EcommerceShippingException::MISSING_SHIPPING_ADDRESS,
                'La orden no tiene una dirección de envío estructurada.',
            );
        }

        $countryCode = strtoupper(trim($address->country_code));
        if (! preg_match('/^[A-Z]{2}$/', $countryCode)) {
            throw new EcommerceShippingException(
                EcommerceShippingException::UNSUPPORTED_DESTINATION,
                'El país de destino no es válido o no está soportado.',
                ['country_code' => $address->country_code],
            );
        }

        return new ShippingAddressData(
            recipientName: $address->recipient_name,
            recipientPhone: $address->recipient_phone,
            countryCode: $countryCode,
            state: $address->state,
            city: $address->city,
            postalCode: $address->postal_code,
            addressLine1: $address->address_line1,
            addressLine2: $address->address_line2,
            deliveryNotes: $address->delivery_notes,
        );
    }

    private function origin(Branch $branch): ShippingOriginData
    {
        $fields = ['country_code', 'state', 'city', 'postal_code', 'address_line1'];
        $missing = collect($fields)->filter(fn (string $field) => blank($branch->{$field}))->values()->all();
        if (! blank($branch->country_code) && ! preg_match('/^[A-Z]{2}$/', strtoupper($branch->country_code))) {
            $missing[] = 'country_code';
        }
        $missing = array_values(array_unique($missing));
        if ($missing !== []) {
            throw new EcommerceShippingException(
                EcommerceShippingException::MISSING_ORIGIN_DATA,
                'La sede seleccionada no tiene configurado un origen logístico completo.',
                ['branch_id' => $branch->id, 'missing_fields' => $missing],
            );
        }

        return new ShippingOriginData(
            branchId: $branch->id,
            branchCode: $branch->code,
            countryCode: strtoupper($branch->country_code),
            state: $branch->state,
            city: $branch->city,
            postalCode: $branch->postal_code,
            addressLine1: $branch->address_line1,
            addressLine2: $branch->address_line2,
        );
    }

    private function validQuote(ShippingQuoteResult $quote, string $currency): bool
    {
        return $quote->amount >= 0
            && $quote->currency === $currency
            && filled($quote->provider)
            && filled($quote->serviceCode)
            && filled($quote->serviceName)
            && filled($quote->quoteReference);
    }

    private function quoteMetadata(ShippingQuoteResult $quote): array
    {
        return $this->sanitizeMetadata([
            ...$quote->metadata,
            'provider' => $quote->provider,
            'carrier' => $quote->carrier,
            'service_code' => $quote->serviceCode,
            'quote_reference' => $quote->quoteReference,
            'estimated_days_min' => $quote->estimatedDaysMin,
            'estimated_days_max' => $quote->estimatedDaysMax,
            'expires_at' => $quote->expiresAt?->toIso8601String(),
        ]);
    }

    private function sanitizeMetadata(array $metadata): array
    {
        $sanitized = [];
        foreach ($metadata as $key => $value) {
            if (is_string($key) && preg_match('/token|secret|password|credential|api.?key|cost_price|inventory/i', $key)) {
                continue;
            }
            $sanitized[$key] = is_array($value) ? $this->sanitizeMetadata($value) : $value;
        }

        return $sanitized;
    }

    private function assertPending(Order $order): void
    {
        if ($order->status !== Order::STATUS_PENDING) {
            throw new EcommerceShippingException(
                EcommerceShippingException::ORDER_NOT_PENDING,
                'Solo una orden pendiente puede cambiar su modalidad de entrega.',
                ['order_id' => $order->id, 'status' => $order->status],
            );
        }
    }
}
