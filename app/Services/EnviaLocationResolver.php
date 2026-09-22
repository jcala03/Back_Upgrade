<?php

namespace App\Services;

use App\Exceptions\EcommerceShippingException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class EnviaLocationResolver
{
    private array $states = [];

    private array $locations = [];

    /** @return array{state: string, city: string} */
    public function resolve(string $countryCode, string $state, string $city): array
    {
        $countryCode = Str::upper(trim($countryCode));
        $stateCode = $this->stateCode($countryCode, $state);

        if ($countryCode !== 'CO' || preg_match('/^\d{8}$/', trim($city))) {
            return ['state' => $stateCode, 'city' => trim($city)];
        }

        $key = $countryCode.'|'.$stateCode.'|'.Str::lower(Str::ascii(trim($city)));
        if (isset($this->locations[$key])) {
            return $this->locations[$key];
        }

        try {
            $response = $this->client(false)->post($this->shippingUrl('/locate'), [
                'city' => trim($city),
                'state' => $stateCode,
                'country' => $countryCode,
            ]);
        } catch (ConnectionException) {
            $this->unavailable('locate');
        }

        $this->assertResponse($response, 'locate');
        $data = $response->json();
        if (! is_array($data)
            || ! preg_match('/^\d{8}$/', (string) ($data['city'] ?? ''))
            || ! preg_match('/^[A-Z]{2}$/', (string) ($data['state'] ?? ''))) {
            throw new EcommerceShippingException(
                EcommerceShippingException::MALFORMED_PROVIDER_RESPONSE,
                'Envia devolvió una respuesta de ubicación inválida.',
                ['operation' => 'locate'],
            );
        }

        return $this->locations[$key] = [
            'state' => Str::upper($data['state']),
            'city' => $data['city'],
        ];
    }

    private function stateCode(string $countryCode, string $state): string
    {
        $state = trim($state);
        if (preg_match('/^[A-Za-z]{2,3}$/', $state)) {
            return Str::upper($state);
        }

        $key = $countryCode.'|'.Str::lower(Str::ascii($state));
        if (isset($this->states[$key])) {
            return $this->states[$key];
        }

        try {
            $response = $this->client()->get($this->queriesUrl('/state'), [
                'country_code' => $countryCode,
            ]);
        } catch (ConnectionException) {
            $this->unavailable('states');
        }

        $this->assertResponse($response, 'states');
        $states = $response->json('data');
        if (! is_array($states)) {
            throw new EcommerceShippingException(
                EcommerceShippingException::MALFORMED_PROVIDER_RESPONSE,
                'Envia devolvió una respuesta de estados inválida.',
                ['operation' => 'states'],
            );
        }

        $normalized = Str::lower(Str::ascii($state));
        foreach ($states as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }
            $name = Str::lower(Str::ascii((string) ($candidate['name'] ?? '')));
            $code = Str::upper((string) ($candidate['code_2_digits'] ?? ''));
            if ($name === $normalized && preg_match('/^[A-Z]{2}$/', $code)) {
                return $this->states[$key] = $code;
            }
        }

        throw new EcommerceShippingException(
            EcommerceShippingException::UNSUPPORTED_DESTINATION,
            'Envia no pudo resolver el estado o departamento indicado.',
            ['country_code' => $countryCode],
        );
    }

    private function client(bool $authenticated = true): PendingRequest
    {
        $request = Http::acceptJson()
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

        return $authenticated
            ? $request->withToken((string) config('services.envia.api_token'))
            : $request;
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
                'Envia no pudo validar la ubicación indicada.',
                ['operation' => $operation, 'status' => 422],
            );
        }
        if ($response->serverError() || ! $response->successful()) {
            $this->unavailable($operation, $response->status());
        }
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
