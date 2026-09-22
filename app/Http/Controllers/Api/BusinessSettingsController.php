<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateBusinessSettingsRequest;
use App\Services\BusinessSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BusinessSettingsController extends Controller
{
    public function __construct(private readonly BusinessSettingsService $settings) {}

    public function show(Request $request): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('settings.view'), 403, 'No tienes permisos para realizar esta acción.');

        return response()->json(['data' => $this->settings->response()]);
    }

    public function update(UpdateBusinessSettingsRequest $request): JsonResponse
    {
        $setting = $this->settings->update($request->validated(), $request->user());

        return response()->json([
            'message' => 'Configuración actualizada correctamente.',
            'data' => $this->settings->response($setting),
        ]);
    }
}
