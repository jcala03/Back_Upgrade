<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserCapability;
use App\Services\CommercialEmployeeContext;
use App\Services\PersonalCommercialDiscoveryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PersonalCommercialDiscoveryController extends Controller
{
    private const CAPABILITIES = [
        UserCapability::QUOTATIONS_CREATE_OWN,
        UserCapability::QUOTATIONS_UPDATE_OWN,
        UserCapability::ORDERS_CREATE_OWN,
    ];

    public function __construct(
        private readonly PersonalCommercialDiscoveryService $discovery,
        private readonly CommercialEmployeeContext $commercialContext,
    ) {}

    public function customers(Request $request): JsonResponse
    {
        $user = $this->authorizedUser($request);
        $this->commercialContext->resolve($user);
        $request->merge(['search' => trim((string) $request->input('search'))]);
        $validated = $request->validate([
            'search' => ['required', 'string', 'min:2', 'max:120'],
        ]);

        return response()->json([
            'data' => $this->discovery->customers($validated['search']),
        ]);
    }

    public function services(Request $request): JsonResponse
    {
        $user = $this->authorizedUser($request);
        $this->commercialContext->resolve($user);
        if ($request->has('search')) {
            $request->merge(['search' => trim((string) $request->input('search'))]);
        }
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'min:2', 'max:120'],
        ]);

        return response()->json([
            'data' => $this->discovery->services($validated['search'] ?? null),
        ]);
    }

    private function authorizedUser(Request $request): User
    {
        $user = $request->user();
        abort_unless(
            $user && collect(self::CAPABILITIES)->contains(
                fn (string $capability): bool => $user->hasPermission($capability),
            ),
            403,
            'No tienes permisos para realizar esta acción.',
        );

        return $user;
    }
}
