<?php

namespace App\Services;

use App\Exceptions\EcommerceShippingException;
use App\Models\Branch;
use App\Models\Order;
use App\Models\OrderAddress;
use App\Models\OrderCharge;
use App\Support\Shipping\ShippingQuoteResult;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PublicCheckoutService
{
    public function __construct(
        private readonly OrderService $orders,
        private readonly ShippingQuoteService $shipping,
        private readonly InternationalCheckoutCostService $landedCosts,
        private readonly EcommerceFulfillmentBranchResolver $fulfillment,
        private readonly OrderTotalService $totals,
    ) {}

    /** @return array{0: Order, 1: string|null, 2: bool} */
    public function create(array $data, string $key): array
    {
        $fingerprint = hash('sha256', json_encode($this->sort($data), JSON_THROW_ON_ERROR));
        $existing = Order::query()->where('checkout_idempotency_key', $key)->first();
        if ($existing) {
            if (! hash_equals((string) $existing->checkout_idempotency_fingerprint, $fingerprint)) {
                throw new EcommerceShippingException('IDEMPOTENCY_CONFLICT', 'La llave de idempotencia ya fue usada con una solicitud distinta.');
            }

            return [$this->load($existing), $this->replayToken($existing), true];
        }

        $token = $this->publicToken($key);
        try {
            $order = $this->orders->createPublic($data, hash('sha256', $token), $key, $fingerprint);
        } catch (QueryException) {
            $existing = Order::query()->where('checkout_idempotency_key', $key)->firstOrFail();
            if (! hash_equals((string) $existing->checkout_idempotency_fingerprint, $fingerprint)) {
                throw new EcommerceShippingException('IDEMPOTENCY_CONFLICT', 'La llave de idempotencia ya fue usada con una solicitud distinta.');
            }

            return [$this->load($existing), $this->replayToken($existing), true];
        }

        return [$this->load($order), $token, false];
    }

    public function find(string $token): Order
    {
        return $this->load(Order::query()->where('public_token_hash', hash('sha256', $token))->firstOrFail());
    }

    public function updateAddress(Order $order, array $data): Order
    {
        return DB::transaction(function () use ($order, $data) {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
            $this->assertCheckoutPending($locked);
            $locked->shippingAddress()->updateOrCreate(['type' => OrderAddress::TYPE_SHIPPING], $data + ['type' => OrderAddress::TYPE_SHIPPING]);
            $this->clearDeliveryCharges($locked);

            return $this->load($this->totals->recalculate($locked));
        });
    }

    /** @return list<array<string, mixed>> */
    public function quotes(Order $order): array
    {
        $this->assertCheckoutPending($order);

        return array_map(fn (ShippingQuoteResult $quote) => [
            'quote_token' => Crypt::encryptString(json_encode([
                'order_id' => $order->id, 'branch_id' => $quote->branchId, 'provider' => $quote->provider,
                'carrier' => $quote->carrier, 'service_code' => $quote->serviceCode, 'service_name' => $quote->serviceName,
                'amount' => $quote->amount, 'currency' => $quote->currency, 'quote_reference' => $quote->quoteReference,
                'estimated_days_min' => $quote->estimatedDaysMin, 'estimated_days_max' => $quote->estimatedDaysMax,
                'expires_at' => $quote->expiresAt?->toIso8601String(), 'address_hash' => $this->addressHash($order),
            ], JSON_THROW_ON_ERROR)),
            'carrier' => $quote->carrier,
            'service' => $quote->serviceName,
            'amount' => $quote->amount,
            'currency' => $quote->currency,
            'estimated_days_min' => $quote->estimatedDaysMin,
            'estimated_days_max' => $quote->estimatedDaysMax,
            'expires_at' => $quote->expiresAt?->toIso8601String(),
        ], $this->shipping->quote($order));
    }

    public function applyQuote(Order $order, string $token): Order
    {
        try {
            $payload = json_decode(Crypt::decryptString($token), true, flags: JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw new EcommerceShippingException(EcommerceShippingException::INVALID_QUOTE, 'La cotización seleccionada no es válida.');
        }
        if (! is_array($payload) || (int) ($payload['order_id'] ?? 0) !== $order->id || ! hash_equals((string) ($payload['address_hash'] ?? ''), $this->addressHash($order))) {
            throw new EcommerceShippingException(EcommerceShippingException::INVALID_QUOTE, 'La cotización no pertenece a esta orden o dirección.');
        }
        $quote = new ShippingQuoteResult(
            (string) ($payload['provider'] ?? ''), (string) ($payload['service_code'] ?? ''), (string) ($payload['service_name'] ?? ''),
            (int) ($payload['amount'] ?? -1), (string) ($payload['currency'] ?? ''), (string) ($payload['quote_reference'] ?? ''),
            $payload['estimated_days_min'] ?? null, $payload['estimated_days_max'] ?? null,
            isset($payload['expires_at']) ? CarbonImmutable::parse($payload['expires_at']) : null, [], (int) ($payload['branch_id'] ?? 0), $payload['carrier'] ?? null,
        );
        $updated = $this->shipping->apply($order, $quote);
        if (Str::upper((string) $updated->shippingAddress?->country_code) !== 'CO') {
            $result = $this->landedCosts->calculate($updated, $quote);
            if (! $result) {
                throw new EcommerceShippingException(EcommerceShippingException::LANDED_COST_UNAVAILABLE, 'No fue posible calcular los costos internacionales.');
            }
            $updated = $this->landedCosts->apply($updated, $quote, $result);
        }

        return $this->load($updated);
    }

    public function pickupBranches(Order $order): array
    {
        return $this->fulfillment->candidates($order)->map(fn (Branch $branch) => ['slug' => $branch->slug, 'name' => $branch->name, 'city' => $branch->city])->all();
    }

    public function applyPickup(Order $order, string $slug): Order
    {
        $branch = $this->fulfillment->candidates($order)->firstWhere('slug', $slug);
        if (! $branch) {
            throw new EcommerceShippingException(EcommerceShippingException::NO_BRANCH_CAN_FULFILL, 'La sede seleccionada no puede surtir la orden completa.');
        }
        $updated = $this->shipping->applyPickup($order, $branch);
        $updated->charges()->whereIn('type', [OrderCharge::TYPE_DUTY, OrderCharge::TYPE_TAX])->delete();

        return $this->load($this->totals->recalculate($updated));
    }

    public function ready(Order $order): bool
    {
        if ($order->status !== Order::STATUS_PENDING || ! $order->branch_id) {
            return false;
        }
        if ($order->fulfillment_type === Order::FULFILLMENT_PICKUP) {
            return true;
        }
        if (! $order->shippingAddress || ! $order->charges->contains('type', OrderCharge::TYPE_SHIPPING)) {
            return false;
        }

        return Str::upper($order->shippingAddress->country_code) === 'CO'
            || ($order->charges->contains('type', OrderCharge::TYPE_DUTY) || $order->charges->contains('type', OrderCharge::TYPE_TAX));
    }

    private function clearDeliveryCharges(Order $order): void
    {
        $order->charges()->whereIn('type', [OrderCharge::TYPE_SHIPPING, OrderCharge::TYPE_DUTY, OrderCharge::TYPE_TAX])->delete();
    }

    private function assertCheckoutPending(Order $order): void
    {
        if ($order->origin !== Order::ORIGIN_ECOMMERCE || $order->status !== Order::STATUS_PENDING) {
            throw new EcommerceShippingException(EcommerceShippingException::ORDER_NOT_PENDING, 'La orden no puede modificarse en checkout.');
        }
    }

    private function addressHash(Order $order): string
    {
        $address = $order->shippingAddress;
        if (! $address) {
            throw new EcommerceShippingException(EcommerceShippingException::MISSING_SHIPPING_ADDRESS, 'La orden no tiene dirección de envío.');
        }

        return hash('sha256', implode('|', [$address->country_code, $address->state, $address->city, $address->postal_code, $address->address_line1, $address->address_line2]));
    }

    private function load(Order $order): Order
    {
        return $order->fresh(['items', 'charges', 'shippingAddress', 'branch']);
    }

    private function publicToken(string $idempotencyKey): string
    {
        return hash_hmac('sha256', 'public-order:'.$idempotencyKey, (string) config('app.key'));
    }

    private function replayToken(Order $order): ?string
    {
        $token = $this->publicToken((string) $order->checkout_idempotency_key);

        return hash_equals((string) $order->public_token_hash, hash('sha256', $token)) ? $token : null;
    }

    private function sort(array $value): array
    {
        foreach ($value as $key => $item) {
            $value[$key] = is_array($item) ? $this->sort($item) : $item;
        } ksort($value);

        return $value;
    }
}
