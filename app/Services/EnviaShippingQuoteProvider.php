<?php

namespace App\Services;

use App\Contracts\ShippingQuoteProvider;
use App\Exceptions\EcommerceShippingException;
use App\Support\Shipping\ShippingAddressData;
use App\Support\Shipping\ShippingOriginData;
use App\Support\Shipping\ShippingPackageData;
use App\Support\Shipping\ShippingQuoteRequest;
use App\Support\Shipping\ShippingQuoteResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class EnviaShippingQuoteProvider implements ShippingQuoteProvider
{
    public function __construct(
        private readonly EnviaLocationResolver $locations,
        private readonly BusinessSettingsService $businessSettings,
    ) {}

    public function quote(ShippingQuoteRequest $request): array
    {
        $this->assertConfigured();
        $identity = $this->businessSettings->identity();
        if (blank($identity['business_name'] ?? null) || blank($identity['phone'] ?? null)) {
            throw new EcommerceShippingException(
                EcommerceShippingException::PROVIDER_CONFIGURATION_ERROR,
                'Envia requiere nombre y teléfono del remitente configurados.',
            );
        }

        $originLocation = $this->locations->resolve(
            $request->origin->countryCode,
            $request->origin->state,
            $request->origin->city,
        );
        $destinationLocation = $this->locations->resolve(
            $request->destination->countryCode,
            $request->destination->state,
            $request->destination->city,
        );
        $international = $request->origin->countryCode !== $request->destination->countryCode;
        $carriers = $this->carriers($request->origin->countryCode, $international);
        $payload = $this->basePayload($request, $identity, $originLocation, $destinationLocation, $international);
        $quotes = [];
        $hasMalformedRates = false;

        foreach ($carriers as $carrier) {
            try {
                $response = $this->client()->post($this->shippingUrl('/ship/rate/'), [
                    ...$payload,
                    'shipment' => ['type' => 1, 'carrier' => $carrier],
                ]);
            } catch (ConnectionException) {
                $this->unavailable('rate');
            }

            $this->assertResponse($response, 'rate');
            $rates = $response->json('data');
            if (! is_array($rates)) {
                $hasMalformedRates = true;

                continue;
            }

            foreach ($rates as $rate) {
                $normalized = is_array($rate) ? $this->normalize($rate, $request) : null;
                if ($normalized) {
                    $quotes[] = $normalized;
                } else {
                    $hasMalformedRates = true;
                }
            }
        }

        if ($quotes === [] && $hasMalformedRates) {
            throw new EcommerceShippingException(
                EcommerceShippingException::MALFORMED_PROVIDER_RESPONSE,
                'Envia devolvió tarifas con una estructura inválida.',
                ['operation' => 'rate'],
            );
        }
        if ($quotes === []) {
            throw new EcommerceShippingException(
                EcommerceShippingException::NO_QUOTES_AVAILABLE,
                'Envia no devolvió tarifas para la ruta solicitada.',
            );
        }

        return $quotes;
    }

    private function basePayload(
        ShippingQuoteRequest $request,
        array $identity,
        array $originLocation,
        array $destinationLocation,
        bool $international,
    ): array {
        $payload = [
            'origin' => $this->origin($request->origin, $originLocation, $identity),
            'destination' => $this->destination($request->destination, $destinationLocation),
            'packages' => array_map(
                fn (ShippingPackageData $package) => $this->package($package, $request->currency, $international),
                $request->packages,
            ),
            'settings' => ['currency' => $request->currency],
        ];

        if ($international) {
            $payload['customsSettings'] = ['exportReason' => 'sale'];
        }

        return $payload;
    }

    private function origin(ShippingOriginData $origin, array $location, array $identity): array
    {
        return [
            'name' => $identity['business_name'],
            'company' => $identity['business_name'],
            'phone' => $identity['phone'],
            'street' => $this->street($origin->addressLine1, $origin->addressLine2),
            'city' => $location['city'],
            'state' => $location['state'],
            'country' => Str::upper($origin->countryCode),
            'postalCode' => $origin->postalCode,
        ];
    }

    private function destination(ShippingAddressData $destination, array $location): array
    {
        return array_filter([
            'name' => $destination->recipientName,
            'phone' => $destination->recipientPhone,
            'street' => $this->street($destination->addressLine1, $destination->addressLine2),
            'city' => $location['city'],
            'state' => $location['state'],
            'country' => Str::upper($destination->countryCode),
            'postalCode' => $destination->postalCode,
            'reference' => $destination->deliveryNotes,
        ], fn ($value) => $value !== null && $value !== '');
    }

    private function package(ShippingPackageData $package, string $currency, bool $international): array
    {
        $payload = [
            'type' => 'box',
            'content' => $package->content,
            'amount' => $package->quantity,
            'declaredValue' => $package->declaredUnitValue * $package->quantity,
            'lengthUnit' => 'CM',
            'weightUnit' => 'KG',
            'weight' => round($package->weightGrams / 1000, 3),
            'dimensions' => [
                'length' => round($package->lengthMm / 10, 2),
                'width' => round($package->widthMm / 10, 2),
                'height' => round($package->heightMm / 10, 2),
            ],
        ];

        if ($international) {
            $payload['items'] = [[
                'description' => $package->customsDescription,
                'productCode' => $package->hsCode,
                'quantity' => $package->quantity,
                'countryOfManufacture' => $package->countryOfOrigin,
                'price' => $package->declaredUnitValue,
                'currency' => $currency,
            ]];
        }

        return $payload;
    }

    /** @return list<string> */
    private function carriers(string $originCountry, bool $international): array
    {
        try {
            $response = $this->client()->get($this->queriesUrl(
                '/available-carrier/'.Str::upper($originCountry).'/'.($international ? '1' : '0').'/1'
            ));
        } catch (ConnectionException) {
            $this->unavailable('carriers');
        }

        $this->assertResponse($response, 'carriers');
        $data = $response->json('data');
        if (! is_array($data)) {
            throw new EcommerceShippingException(
                EcommerceShippingException::MALFORMED_PROVIDER_RESPONSE,
                'Envia devolvió una respuesta de carriers inválida.',
                ['operation' => 'carriers'],
            );
        }

        $carriers = collect($data)
            ->filter(fn ($carrier) => is_array($carrier))
            ->map(fn (array $carrier) => trim((string) ($carrier['code'] ?? $carrier['name'] ?? '')))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($carriers === []) {
            throw new EcommerceShippingException(
                EcommerceShippingException::NO_QUOTES_AVAILABLE,
                'Envia no reportó carriers disponibles para la ruta solicitada.',
                ['operation' => 'carriers'],
            );
        }

        return $carriers;
    }

    private function normalize(array $rate, ShippingQuoteRequest $request): ?ShippingQuoteResult
    {
        $carrier = trim((string) ($rate['carrier'] ?? ''));
        $service = trim((string) ($rate['service'] ?? ''));
        $serviceName = trim((string) ($rate['serviceDescription'] ?? $service));
        $currency = Str::upper((string) ($rate['currency'] ?? ''));
        $totalPrice = $rate['totalPrice'] ?? null;
        if ($carrier === '' || $service === '' || $serviceName === ''
            || $currency !== $request->currency || ! is_numeric($totalPrice) || (float) $totalPrice < 0) {
            return null;
        }

        [$daysMin, $daysMax] = $this->transitDays($rate);
        $amount = (int) round((float) $totalPrice);
        $reference = 'envia-'.hash('sha256', implode('|', [
            $carrier,
            $service,
            (string) $totalPrice,
            $currency,
            $request->destination->countryCode,
            $request->destination->postalCode,
        ]));

        return new ShippingQuoteResult(
            provider: 'envia',
            serviceCode: $service,
            serviceName: $serviceName,
            amount: $amount,
            currency: $currency,
            quoteReference: $reference,
            estimatedDaysMin: $daysMin,
            estimatedDaysMax: $daysMax,
            metadata: array_filter([
                'carrier_id' => $rate['carrierId'] ?? null,
                'carrier_description' => $rate['carrierDescription'] ?? null,
                'service_id' => $rate['serviceId'] ?? null,
                'delivery_estimate' => $rate['deliveryEstimate'] ?? null,
                'delivery_date' => is_array($rate['deliveryDate'] ?? null)
                    ? array_intersect_key($rate['deliveryDate'], array_flip(['date', 'dateDifference', 'timeUnit', 'time']))
                    : null,
                'drop_off' => $rate['dropOffDescription'] ?? null,
            ], fn ($value) => $value !== null),
            carrier: $carrier,
        );
    }

    /** @return array{?int, ?int} */
    private function transitDays(array $rate): array
    {
        $difference = $rate['deliveryDate']['dateDifference'] ?? null;
        $unit = Str::lower((string) ($rate['deliveryDate']['timeUnit'] ?? ''));
        if (is_numeric($difference) && in_array($unit, ['day', 'days'], true)) {
            $days = max(0, (int) $difference);

            return [$days, $days];
        }
        if (is_numeric($difference) && in_array($unit, ['hour', 'hours'], true)) {
            $days = max(1, (int) ceil(((float) $difference) / 24));

            return [$days, $days];
        }

        preg_match_all('/\d+/', (string) ($rate['deliveryEstimate'] ?? ''), $matches);
        $days = array_map('intval', $matches[0] ?? []);

        return match (count($days)) {
            0 => [null, null],
            1 => [$days[0], $days[0]],
            default => [$days[0], $days[1]],
        };
    }

    private function client(): PendingRequest
    {
        return Http::withToken((string) config('services.envia.api_token'))
            ->acceptJson()
            ->asJson()
            ->connectTimeout((int) config('services.envia.connect_timeout', 5))
            ->timeout((int) config('services.envia.timeout', 15))
            ->retry(
                max(1, (int) config('services.envia.retries', 2)),
                100,
                fn (Throwable $exception) => $exception instanceof ConnectionException
                    || ($exception instanceof RequestException && $exception->response->serverError()),
                false,
            );
    }

    private function assertConfigured(): void
    {
        if (blank(config('services.envia.api_token'))
            || ! filter_var(config('services.envia.base_url'), FILTER_VALIDATE_URL)
            || ! filter_var(config('services.envia.queries_base_url'), FILTER_VALIDATE_URL)) {
            throw new EcommerceShippingException(
                EcommerceShippingException::PROVIDER_CONFIGURATION_ERROR,
                'La integración de Envia no está configurada correctamente.',
            );
        }
    }

    private function assertResponse(Response $response, string $operation): void
    {
        if (in_array($response->status(), [401, 403], true)) {
            throw new EcommerceShippingException(
                EcommerceShippingException::PROVIDER_AUTHENTICATION_FAILED,
                'Envia rechazó las credenciales configuradas.',
                ['operation' => $operation, 'status' => $response->status()],
            );
        }
        if ($response->status() === 422) {
            throw new EcommerceShippingException(
                EcommerceShippingException::INVALID_ADDRESS,
                'Envia rechazó la dirección o los datos de cotización.',
                ['operation' => $operation, 'status' => 422],
            );
        }
        if ($response->serverError() || ! $response->successful()) {
            $this->unavailable($operation, $response->status());
        }
    }

    private function street(string $line1, ?string $line2): string
    {
        return implode(', ', array_filter([trim($line1), $line2 ? trim($line2) : null]));
    }

    private function shippingUrl(string $path): string
    {
        return rtrim((string) config('services.envia.base_url'), '/').$path;
    }

    private function queriesUrl(string $path): string
    {
        return rtrim((string) config('services.envia.queries_base_url'), '/').$path;
    }

    private function unavailable(string $operation, ?int $status = null): never
    {
        throw new EcommerceShippingException(
            EcommerceShippingException::PROVIDER_UNAVAILABLE,
            'El servicio de Envia no está disponible temporalmente.',
            array_filter(['operation' => $operation, 'status' => $status]),
        );
    }
}
