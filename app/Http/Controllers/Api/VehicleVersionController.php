<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\VehicleMultimediaSystem;
use App\Models\VehicleVersion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class VehicleVersionController extends Controller
{
    public function index(Request $request)
    {
        $versions = VehicleVersion::query()
            ->with(['model.brand', 'multimediaSystems.vehicleBrand'])
            ->where('is_active', true)
            ->when($request->filled('vehicle_model_id'), function ($query) use ($request) {
                $query->where('vehicle_model_id', $request->integer('vehicle_model_id'));
            })
            ->when($request->filled('vehicle_brand_id'), function ($query) use ($request) {
                $query->whereHas('model', function ($modelQuery) use ($request) {
                    $modelQuery->where('vehicle_brand_id', $request->integer('vehicle_brand_id'));
                });
            })
            ->orderBy('year_from')
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $versions,
        ]);
    }

    public function adminIndex(Request $request)
    {
        $this->authorizePermission($request, 'products.view');

        $versions = VehicleVersion::query()
            ->with(['model.brand', 'multimediaSystems.vehicleBrand'])
            ->when($request->filled('vehicle_model_id'), function ($query) use ($request) {
                $query->where('vehicle_model_id', $request->integer('vehicle_model_id'));
            })
            ->when($request->filled('vehicle_brand_id'), function ($query) use ($request) {
                $query->whereHas('model', function ($modelQuery) use ($request) {
                    $modelQuery->where('vehicle_brand_id', $request->integer('vehicle_brand_id'));
                });
            })
            ->latest()
            ->get();

        return response()->json([
            'data' => $versions,
        ]);
    }

    public function store(Request $request)
    {
        $this->authorizePermission($request, 'products.create');

        $validated = $this->validateVersion($request);

        $this->ensureUniqueVersion($validated);

        $version = VehicleVersion::create([
            'vehicle_model_id' => $validated['vehicle_model_id'],
            'name' => $validated['name'] ?? null,
            'year_from' => $validated['year_from'],
            'year_to' => $validated['year_to'] ?? null,
            'description' => $validated['description'] ?? null,
            'is_active' => $request->boolean('is_active', true),
        ]);

        return response()->json([
            'message' => 'Versión de vehículo creada correctamente.',
            'data' => $version->load(['model.brand', 'multimediaSystems.vehicleBrand']),
        ], 201);
    }

    public function update(Request $request, VehicleVersion $vehicleVersion)
    {
        $this->authorizePermission($request, 'products.update');

        $validated = $this->validateVersion($request);

        $this->ensureUniqueVersion($validated, $vehicleVersion->id);

        $vehicleVersion->update([
            'vehicle_model_id' => $validated['vehicle_model_id'],
            'name' => $validated['name'] ?? null,
            'year_from' => $validated['year_from'],
            'year_to' => $validated['year_to'] ?? null,
            'description' => $validated['description'] ?? null,
            'is_active' => $request->boolean('is_active', true),
        ]);

        return response()->json([
            'message' => 'Versión de vehículo actualizada correctamente.',
            'data' => $vehicleVersion->fresh()->load('model.brand'),
        ]);
    }

    public function destroy(Request $request, VehicleVersion $vehicleVersion)
    {
        $this->authorizePermission($request, 'products.delete');

        $vehicleVersion->update([
            'is_active' => false,
        ]);

        return response()->json([
            'message' => 'Versión de vehículo desactivada correctamente.',
        ]);
    }

    public function syncMultimediaSystems(
        Request $request,
        VehicleVersion $vehicleVersion
    ): JsonResponse {
        $this->authorizePermission($request, 'products.update');

        $validated = $request->validate([
            'multimedia_systems' => ['present', 'array'],
            'multimedia_systems.*.vehicle_multimedia_system_id' => [
                'required',
                'integer',
                'exists:vehicle_multimedia_systems,id',
            ],
            'multimedia_systems.*.year_from' => ['nullable', 'integer'],
            'multimedia_systems.*.year_to' => ['nullable', 'integer'],
        ]);

        $vehicleVersion->loadMissing('model.brand');
        $systems = VehicleMultimediaSystem::query()
            ->whereKey(collect($validated['multimedia_systems'])
                ->pluck('vehicle_multimedia_system_id')
                ->unique()
                ->values())
            ->get()
            ->keyBy('id');

        $syncData = $this->validatedMultimediaSystemSyncData(
            $validated['multimedia_systems'],
            $vehicleVersion,
            $systems
        );

        DB::transaction(function () use ($vehicleVersion, $syncData) {
            $vehicleVersion->multimediaSystems()->sync($syncData);
        });

        return response()->json([
            'message' => 'Sistemas multimedia de la versión actualizados correctamente.',
            'data' => $vehicleVersion->fresh()->load([
                'model.brand',
                'multimediaSystems.vehicleBrand',
            ]),
        ]);
    }

    private function validatedMultimediaSystemSyncData(
        array $entries,
        VehicleVersion $vehicleVersion,
        $systems
    ): array {
        $syncData = [];
        $vehicleBrandId = (int) $vehicleVersion->model->vehicle_brand_id;

        foreach ($entries as $index => $entry) {
            $systemId = (int) $entry['vehicle_multimedia_system_id'];
            $yearFrom = isset($entry['year_from']) ? (int) $entry['year_from'] : null;
            $yearTo = isset($entry['year_to']) ? (int) $entry['year_to'] : null;
            $system = $systems->get($systemId);
            $field = "multimedia_systems.{$index}";

            if ((int) $system->vehicle_brand_id !== $vehicleBrandId) {
                throw ValidationException::withMessages([
                    "{$field}.vehicle_multimedia_system_id" => 'El sistema multimedia debe pertenecer a la misma marca de la versión.',
                ]);
            }

            if ($yearFrom !== null && $yearTo !== null && $yearFrom > $yearTo) {
                throw ValidationException::withMessages([
                    "{$field}.year_to" => 'El año final debe ser mayor o igual al año inicial.',
                ]);
            }

            foreach (['year_from' => $yearFrom, 'year_to' => $yearTo] as $yearField => $year) {
                if ($year !== null && $year < $vehicleVersion->year_from) {
                    throw ValidationException::withMessages([
                        "{$field}.{$yearField}" => 'El año debe estar dentro del rango de la versión del vehículo.',
                    ]);
                }

                if ($year !== null && $vehicleVersion->year_to !== null && $year > $vehicleVersion->year_to) {
                    throw ValidationException::withMessages([
                        "{$field}.{$yearField}" => 'El año debe estar dentro del rango de la versión del vehículo.',
                    ]);
                }
            }

            $pivot = ['year_from' => $yearFrom, 'year_to' => $yearTo];

            if (isset($syncData[$systemId])) {
                if ($syncData[$systemId] !== $pivot) {
                    throw ValidationException::withMessages([
                        "{$field}.vehicle_multimedia_system_id" => 'Cada sistema multimedia admite un único rango por versión.',
                    ]);
                }

                continue;
            }

            $syncData[$systemId] = $pivot;
        }

        return $syncData;
    }

    private function validateVersion(Request $request): array
    {
        return $request->validate([
            'vehicle_model_id' => ['required', 'integer', 'exists:vehicle_models,id'],
            'name' => ['nullable', 'string', 'max:120'],
            'year_from' => ['required', 'integer', 'min:1900', 'max:2100'],
            'year_to' => ['nullable', 'integer', 'min:1900', 'max:2100', 'gte:year_from'],
            'description' => ['nullable', 'string'],
            'is_active' => ['nullable', 'boolean'],
        ]);
    }

    private function ensureUniqueVersion(array $validated, ?int $ignoreVersionId = null): void
    {
        $exists = VehicleVersion::query()
            ->where('vehicle_model_id', $validated['vehicle_model_id'])
            ->where('name', $validated['name'] ?? null)
            ->where('year_from', $validated['year_from'])
            ->where('year_to', $validated['year_to'] ?? null)
            ->when($ignoreVersionId, function ($query) use ($ignoreVersionId) {
                $query->where('id', '!=', $ignoreVersionId);
            })
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'year_from' => 'Ya existe una versión con ese modelo, nombre y rango de años.',
            ]);
        }
    }

    private function authorizePermission(Request $request, string $permission): void
    {
        $user = $request->user();

        if (! $user || ! $user->hasPermission($permission)) {
            abort(403, 'No tienes permiso para realizar esta acción.');
        }
    }
}
