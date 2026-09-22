<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\EcommerceShippingException;
use App\Http\Controllers\Controller;
use App\Http\Resources\PublicOrderResource;
use App\Models\Order;
use App\Services\PublicCheckoutService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CheckoutOrderController extends Controller
{
    public function __construct(private readonly PublicCheckoutService $checkout) {}

    public function show(string $publicToken, Request $request): JsonResponse
    {
        return $this->respond(fn () => $this->resource($this->checkout->find($publicToken), $request), 404);
    }

    public function address(string $publicToken, Request $request): JsonResponse
    {
        $data = $request->validate([
            'recipient_name' => ['required', 'string', 'max:120'], 'recipient_phone' => ['required', 'string', 'max:40'],
            'country_code' => ['required', 'string', 'size:2', 'regex:/^[A-Z]{2}$/'], 'state' => ['required', 'string', 'max:120'], 'city' => ['required', 'string', 'max:120'],
            'postal_code' => ['required', 'string', 'max:20'], 'address_line1' => ['required', 'string', 'max:220'], 'address_line2' => ['nullable', 'string', 'max:220'], 'delivery_notes' => ['nullable', 'string', 'max:1000'],
        ]);

        return $this->respond(fn () => $this->resource($this->checkout->updateAddress($this->checkout->find($publicToken), $data), $request));
    }

    public function quotes(string $publicToken): JsonResponse
    {
        return $this->respond(fn () => ['quotes' => $this->checkout->quotes($this->checkout->find($publicToken))]);
    }

    public function applyQuote(string $publicToken, Request $request): JsonResponse
    {
        $data = $request->validate(['quote_token' => ['required', 'string', 'max:10000']]);

        return $this->respond(fn () => $this->resource($this->checkout->applyQuote($this->checkout->find($publicToken), $data['quote_token']), $request));
    }

    public function pickupBranches(string $publicToken): JsonResponse
    {
        return $this->respond(fn () => ['branches' => $this->checkout->pickupBranches($this->checkout->find($publicToken))]);
    }

    public function applyPickup(string $publicToken, Request $request): JsonResponse
    {
        $data = $request->validate(['branch_slug' => ['required', 'string', 'max:160']]);

        return $this->respond(fn () => $this->resource($this->checkout->applyPickup($this->checkout->find($publicToken), $data['branch_slug']), $request));
    }

    private function resource(Order $order, Request $request): array
    {
        return (new PublicOrderResource($order))->resolve($request) + ['ready_for_payment' => $this->checkout->ready($order)];
    }

    private function respond(callable $callback, int $notFoundStatus = 404): JsonResponse
    {
        try {
            return response()->json(['data' => $callback()]);
        } catch (ModelNotFoundException) {
            return response()->json(['message' => 'Recurso no encontrado.'], $notFoundStatus);
        } catch (EcommerceShippingException $exception) {
            return response()->json(['message' => 'No fue posible completar el checkout.', 'code' => $exception->errorCode], $exception->errorCode === EcommerceShippingException::ORDER_NOT_PENDING ? 409 : 422);
        }
    }
}
