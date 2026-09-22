<?php

namespace App\Services;

use App\Contracts\LandedCostProvider;
use App\Exceptions\EcommerceShippingException;
use App\Support\LandedCost\LandedCostCharge;
use App\Support\LandedCost\LandedCostItemData;
use App\Support\LandedCost\LandedCostRequest;
use App\Support\LandedCost\LandedCostResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class DhlLandedCostProvider implements LandedCostProvider
{
    public function estimate(LandedCostRequest $request): LandedCostResult
    {
        $this->assertConfigured();
        $this->assertRequest($request);

        try {
            $response = $this->client()->post($this->url('/landed-cost'), $this->payload($request));
        } catch (ConnectionException) {
            $this->unavailable();
        }

        $this->assertResponse($response);

        return $this->normalize($response, $request);
    }

    private function payload(LandedCostRequest $request): array
    {
        $charges = [[
            'typeCode' => 'freight',
            'amount' => $request->shippingAmount,
            'currencyCode' => $request->currency,
        ]];
        if ($request->insuranceAmount > 0) {
            $charges[] = [
                'typeCode' => 'insurance',
                'amount' => $request->insuranceAmount,
                'currencyCode' => $request->currency,
            ];
        }

        return [
            'customerDetails' => [
                'shipperDetails' => $this->address($request->origin->countryCode, $request->origin->state, $request->origin->city, $request->origin->postalCode, $request->origin->addressLine1, $request->origin->addressLine2),
                'receiverDetails' => $this->address($request->destination->countryCode, $request->destination->state, $request->destination->city, $request->destination->postalCode, $request->destination->addressLine1, $request->destination->addressLine2),
            ],
            'accounts' => [[
                'typeCode' => 'shipper',
                'number' => (string) config('services.dhl_mydhl.account_number'),
            ]],
            'unitOfMeasurement' => 'metric',
            'currencyCode' => $request->currency,
            'isCustomsDeclarable' => true,
            'getCostBreakdown' => true,
            'charges' => $charges,
            'shipmentPurpose' => 'personal',
            'transportationMode' => 'air',
            'merchantSelectedCarrierName' => $this->carrier($request->carrier),
            'packages' => array_map(fn (LandedCostItemData $item) => $this->package($item), $request->items),
            'items' => array_map(fn (LandedCostItemData $item, int $index) => $this->item($item, $index + 1, $request->currency), $request->items, array_keys($request->items)),
            'getTariffFormula' => false,
            'getQuotationID' => true,
        ];
    }

    private function address(string $country, string $state, string $city, string $postalCode, string $line1, ?string $line2): array
    {
        return array_filter([
            'countryCode' => Str::upper($country),
            'provinceCode' => trim($state),
            'cityName' => trim($city),
            'postalCode' => trim($postalCode),
            'addressLine1' => trim($line1),
            'addressLine2' => $line2 ? trim($line2) : null,
        ], fn ($value) => $value !== null && $value !== '');
    }

    private function package(LandedCostItemData $item): array
    {
        return [
            'weight' => round($item->weightGrams / 1000, 3),
            'dimensions' => [
                'length' => round($item->lengthMm / 10, 2),
                'width' => round($item->widthMm / 10, 2),
                'height' => round($item->heightMm / 10, 2),
            ],
        ];
    }

    private function item(LandedCostItemData $item, int $number, string $currency): array
    {
        return [
            'number' => $number,
            'name' => $item->name,
            'description' => $item->customsDescription,
            'manufacturerCountry' => Str::upper($item->countryOfOrigin),
            'partNumber' => (string) $item->orderItemId,
            'quantity' => $item->quantity,
            'quantityType' => 'prt',
            'unitPrice' => $item->declaredUnitValue,
            'unitPriceCurrencyCode' => $currency,
            'commodityCode' => $item->hsCode,
            'weight' => round($item->weightGrams / 1000, 3),
            'weightUnitOfMeasurement' => 'metric',
        ];
    }

    private function normalize(Response $response, LandedCostRequest $request): LandedCostResult
    {
        $product = collect($response->json('products'))->first(fn ($candidate) => is_array($candidate));
        if (! is_array($product)) {
            $this->malformed();
        }

        $total = $this->money($product['totalPrice'] ?? null, $request->currency);
        $breakdown = $this->breakdown($product['detailedPriceBreakdown'] ?? null, $request->currency);
        if ($total === null && $this->hasCurrency($product['totalPrice'] ?? null)) {
            throw new EcommerceShippingException(EcommerceShippingException::CURRENCY_MISMATCH, 'DHL MyDHL devolvió landed cost en una moneda distinta a la solicitada.');
        }
        if ($total === null || $breakdown === null) {
            $this->malformed();
        }

        $charges = $this->charges($breakdown, $request->currency);
        $reference = trim((string) $response->header('Quotation-Id'));
        if ($reference === '') {
            $reference = 'dhl-'.hash('sha256', implode('|', [$request->currency, $total, $request->destination->countryCode, $request->destination->postalCode, $request->shippingAmount]));
        }

        return new LandedCostResult(
            provider: 'dhl_mydhl',
            charges: $charges,
            totalLandedCost: $total,
            currency: $request->currency,
            quoteReference: $reference,
            estimated: true,
            metadata: array_filter([
                'quotation_id' => $response->header('Quotation-Id') ?: null,
                'invocation_id' => $response->header('Invocation-Id') ?: null,
                'calculated_at' => Carbon::now()->toIso8601String(),
                'estimated' => true,
            ]),
        );
    }

    /** @return list<array<string, mixed>>|null */
    private function breakdown(mixed $values, string $currency): ?array
    {
        if (! is_array($values)) {
            return null;
        }
        foreach ($values as $entry) {
            if (is_array($entry) && Str::upper((string) ($entry['priceCurrency'] ?? '')) === $currency && is_array($entry['breakdown'] ?? null)) {
                return $entry['breakdown'];
            }
        }

        return null;
    }

    private function money(mixed $values, string $currency): ?int
    {
        if (! is_array($values)) {
            return null;
        }
        foreach ($values as $entry) {
            if (is_array($entry) && Str::upper((string) ($entry['priceCurrency'] ?? '')) === $currency && is_numeric($entry['price'] ?? null) && (float) $entry['price'] >= 0) {
                return (int) round((float) $entry['price']);
            }
        }

        return null;
    }

    private function hasCurrency(mixed $values): bool
    {
        return is_array($values) && collect($values)->contains(fn ($entry) => is_array($entry) && filled($entry['priceCurrency'] ?? null));
    }

    /** @param list<array<string, mixed>> $breakdown
     * @return list<LandedCostCharge>
     */
    private function charges(array $breakdown, string $currency): array
    {
        $typed = collect($breakdown)
            ->filter(fn ($line) => is_array($line) && in_array(Str::upper((string) ($line['typeCode'] ?? '')), ['DUTY', 'TAX', 'FEE'], true))
            ->filter(fn (array $line) => Str::upper((string) ($line['priceCurrency'] ?? '')) === $currency && is_numeric($line['price'] ?? null));

        $charges = [];
        foreach (['DUTY' => LandedCostCharge::TYPE_DUTY, 'TAX' => LandedCostCharge::TYPE_TAX] as $dhlType => $type) {
            $summary = $typed->first(fn (array $line) => Str::upper(trim((string) ($line['name'] ?? ''))) === 'TOTAL '.($dhlType === 'DUTY' ? 'DUTIES' : 'TAXES'));
            $lines = $summary ? collect([$summary]) : $typed->filter(fn (array $line) => Str::upper((string) ($line['typeCode'] ?? '')) === $dhlType);
            foreach ($lines as $line) {
                $charges[] = $this->charge($line, $type, $currency);
            }
        }

        $fees = $typed->filter(fn (array $line) => Str::upper((string) ($line['typeCode'] ?? '')) === 'FEE');
        if ($fees->count() > 1) {
            $fees = $fees->reject(fn (array $line) => Str::upper(trim((string) ($line['name'] ?? ''))) === 'TOTAL FEES');
        }
        foreach ($fees as $line) {
            $charges[] = $this->charge($line, LandedCostCharge::TYPE_FEE, $currency);
        }

        return array_values(array_filter($charges, fn (LandedCostCharge $charge) => $charge->amount >= 0));
    }

    private function charge(array $line, string $type, string $currency): LandedCostCharge
    {
        $label = trim((string) ($line['name'] ?? $line['serviceCode'] ?? $type));
        $code = trim((string) ($line['serviceCode'] ?? $line['typeCode'] ?? $type));

        return new LandedCostCharge($type, $code, $label, (int) round((float) $line['price']), $currency);
    }

    private function carrier(?string $carrier): string
    {
        return match (Str::lower(trim((string) $carrier))) {
            'dhl' => 'DHL',
            'ups' => 'UPS',
            'fedex', 'fedex express' => 'FEDEX',
            'tnt' => 'TNT',
            'post', 'postal' => 'POST',
            default => 'OTHERS',
        };
    }

    private function assertRequest(LandedCostRequest $request): void
    {
        if ($request->origin->countryCode === $request->destination->countryCode) {
            throw new EcommerceShippingException(EcommerceShippingException::UNSUPPORTED_DESTINATION, 'DHL Landed Cost sólo se consulta para destinos internacionales.');
        }
        if (! preg_match('/^[A-Z]{2}$/', Str::upper($request->origin->countryCode)) || ! preg_match('/^[A-Z]{2}$/', Str::upper($request->destination->countryCode))) {
            throw new EcommerceShippingException(EcommerceShippingException::UNSUPPORTED_DESTINATION, 'El país de origen o destino no es válido.');
        }
        if ($request->items === []) {
            throw new EcommerceShippingException(EcommerceShippingException::MISSING_CUSTOMS_DATA, 'No hay artículos aduaneros para estimar landed cost.');
        }
    }

    private function assertConfigured(): void
    {
        if (blank(config('services.dhl_mydhl.username')) || blank(config('services.dhl_mydhl.password')) || blank(config('services.dhl_mydhl.account_number')) || ! filter_var(config('services.dhl_mydhl.base_url'), FILTER_VALIDATE_URL)) {
            throw new EcommerceShippingException(EcommerceShippingException::LANDED_COST_CONFIG_MISSING, 'La integración DHL MyDHL Landed Cost no está configurada correctamente.');
        }
    }

    private function client(): PendingRequest
    {
        return Http::withBasicAuth((string) config('services.dhl_mydhl.username'), (string) config('services.dhl_mydhl.password'))
            ->acceptJson()->asJson()
            ->withHeaders([
                'Message-Reference' => (string) Str::uuid(),
                'Message-Reference-Date' => Carbon::now()->toIso8601String(),
            ])
            ->connectTimeout((int) config('services.dhl_mydhl.connect_timeout', 5))
            ->timeout((int) config('services.dhl_mydhl.timeout', 15))
            ->retry(max(1, (int) config('services.dhl_mydhl.retries', 2)), 100, fn (Throwable $exception) => $exception instanceof ConnectionException || ($exception instanceof RequestException && $exception->response->serverError()), false);
    }

    private function assertResponse(Response $response): void
    {
        if (in_array($response->status(), [401, 403], true)) {
            throw new EcommerceShippingException(EcommerceShippingException::LANDED_COST_AUTHENTICATION_FAILED, 'DHL MyDHL rechazó las credenciales configuradas.', ['status' => $response->status()]);
        }
        if ($response->clientError()) {
            throw new EcommerceShippingException(EcommerceShippingException::LANDED_COST_INVALID_REQUEST, 'DHL MyDHL rechazó los datos de landed cost.', ['status' => $response->status()]);
        }
        if ($response->serverError() || ! $response->successful()) {
            $this->unavailable($response->status());
        }
    }

    private function url(string $path): string
    {
        return rtrim((string) config('services.dhl_mydhl.base_url'), '/').$path;
    }

    private function unavailable(?int $status = null): never
    {
        throw new EcommerceShippingException(EcommerceShippingException::LANDED_COST_UNAVAILABLE, 'DHL MyDHL Landed Cost no está disponible temporalmente.', array_filter(['status' => $status]));
    }

    private function malformed(): never
    {
        throw new EcommerceShippingException(EcommerceShippingException::MALFORMED_PROVIDER_RESPONSE, 'DHL MyDHL devolvió una respuesta de landed cost inválida.');
    }
}
