<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\EcommercePaymentException;
use App\Http\Controllers\Controller;
use App\Http\Requests\InitializeEcommercePaymentRequest;
use App\Http\Resources\PublicWompiCheckoutResource;
use App\Services\EcommercePaymentService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;

class CheckoutPaymentController extends Controller
{
    public function __construct(private readonly EcommercePaymentService $payments) {}

    public function store(InitializeEcommercePaymentRequest $request, string $publicToken): JsonResponse
    {
        try {
            $result = $this->payments->initialize($publicToken, $request->validated('idempotency_key'));

            return response()->json([
                'data' => (new PublicWompiCheckoutResource($result['payload']))->resolve($request),
            ], $result['replayed'] ? 200 : 201)->header('Cache-Control', 'no-store');
        } catch (ModelNotFoundException) {
            return response()->json(['message' => 'Recurso no encontrado.'], 404);
        } catch (EcommercePaymentException $exception) {
            return response()->json([
                'message' => $exception->getMessage(), 'code' => $exception->errorCode,
            ], $exception->httpStatus());
        }
    }
}
