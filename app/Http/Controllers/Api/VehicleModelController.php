<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\VehicleModel;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class VehicleModelController extends Controller
{
    public function index(Request $request)
    {
        $models = VehicleModel::query()
            ->with([
                'brand',
                'versions' => function ($query) {
                    $query
                        ->where('is_active', true)
                        ->orderBy('year_from')
                        ->orderBy('name');
                },
            ])
            ->where('is_active', true)
            ->when($request->filled('vehicle_brand_id'), function ($query) use ($request) {
                $query->where('vehicle_brand_id', $request->integer('vehicle_brand_id'));
            })
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $models,
        ]);
    }

    public function adminIndex(Request $request)
    {
        $this->authorizePermission($request, 'products.view');

        $models = VehicleModel::query()
            ->with('brand', 'versions')
            ->when($request->filled('vehicle_brand_id'), function ($query) use ($request) {
                $query->where('vehicle_brand_id', $request->integer('vehicle_brand_id'));
            })
            ->latest()
            ->get();

        return response()->json([
            'data' => $models,
        ]);
    }

    public function store(Request $request)
    {
        $this->authorizePermission($request, 'products.create');

        $validated = $request->validate([
            'vehicle_brand_id' => ['required', 'integer', 'exists:vehicle_brands,id'],
            'name' => [
                'required',
                'string',
                'max:120',
                Rule::unique('vehicle_models', 'name')
                    ->where('vehicle_brand_id', $request->integer('vehicle_brand_id')),
            ],
            'description' => ['nullable', 'string'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $model = VehicleModel::create([
            'vehicle_brand_id' => $validated['vehicle_brand_id'],
            'name' => $validated['name'],
            'slug' => $this->generateUniqueSlug(
                (int) $validated['vehicle_brand_id'],
                $validated['name']
            ),
            'description' => $validated['description'] ?? null,
            'is_active' => $request->boolean('is_active', true),
        ]);

        return response()->json([
            'message' => 'Modelo de vehículo creado correctamente.',
            'data' => $model->load('brand', 'versions'),
        ], 201);
    }

    public function update(Request $request, VehicleModel $vehicleModel)
    {
        $this->authorizePermission($request, 'products.update');

        $validated = $request->validate([
            'vehicle_brand_id' => ['required', 'integer', 'exists:vehicle_brands,id'],
            'name' => [
                'required',
                'string',
                'max:120',
                Rule::unique('vehicle_models', 'name')
                    ->where('vehicle_brand_id', $request->integer('vehicle_brand_id'))
                    ->ignore($vehicleModel->id),
            ],
            'description' => ['nullable', 'string'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $shouldUpdateSlug = $vehicleModel->name !== $validated['name']
            || $vehicleModel->vehicle_brand_id !== (int) $validated['vehicle_brand_id'];

        $vehicleModel->update([
            'vehicle_brand_id' => $validated['vehicle_brand_id'],
            'name' => $validated['name'],
            'slug' => $shouldUpdateSlug
                ? $this->generateUniqueSlug(
                    (int) $validated['vehicle_brand_id'],
                    $validated['name'],
                    $vehicleModel->id
                )
                : $vehicleModel->slug,
            'description' => $validated['description'] ?? null,
            'is_active' => $request->boolean('is_active', true),
        ]);

        return response()->json([
            'message' => 'Modelo de vehículo actualizado correctamente.',
            'data' => $vehicleModel->fresh()->load('brand', 'versions'),
        ]);
    }

    public function destroy(Request $request, VehicleModel $vehicleModel)
    {
        $this->authorizePermission($request, 'products.delete');

        $vehicleModel->update([
            'is_active' => false,
        ]);

        return response()->json([
            'message' => 'Modelo de vehículo desactivado correctamente.',
        ]);
    }

    private function generateUniqueSlug(int $vehicleBrandId, string $name, ?int $ignoreModelId = null): string
    {
        $baseSlug = Str::slug($name);
        $slug = $baseSlug;
        $counter = 2;

        while (
            VehicleModel::query()
                ->where('vehicle_brand_id', $vehicleBrandId)
                ->where('slug', $slug)
                ->when($ignoreModelId, function ($query) use ($ignoreModelId) {
                    $query->where('id', '!=', $ignoreModelId);
                })
                ->exists()
        ) {
            $slug = "{$baseSlug}-{$counter}";
            $counter++;
        }

        return $slug;
    }

    private function authorizePermission(Request $request, string $permission): void
    {
        $user = $request->user();

        if (! $user || ! $user->hasPermission($permission)) {
            abort(403, 'No tienes permiso para realizar esta acción.');
        }
    }
}
