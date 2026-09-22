<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\VehicleBrand;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class VehicleBrandController extends Controller
{
    public function index()
    {
        $brands = VehicleBrand::query()
            ->with([
                'models' => function ($query) {
                    $query
                        ->where('is_active', true)
                        ->orderBy('name');
                },
            ])
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $brands,
        ]);
    }

    public function adminIndex(Request $request)
    {
        $this->authorizePermission($request, 'products.view');

        $brands = VehicleBrand::query()
            ->with('models.versions')
            ->latest()
            ->get();

        return response()->json([
            'data' => $brands,
        ]);
    }

    public function store(Request $request)
    {
        $this->authorizePermission($request, 'products.create');

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120', 'unique:vehicle_brands,name'],
            'description' => ['nullable', 'string'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $brand = VehicleBrand::create([
            'name' => $validated['name'],
            'slug' => $this->generateUniqueSlug($validated['name']),
            'description' => $validated['description'] ?? null,
            'is_active' => $request->boolean('is_active', true),
        ]);

        return response()->json([
            'message' => 'Marca de vehículo creada correctamente.',
            'data' => $brand,
        ], 201);
    }

    public function update(Request $request, VehicleBrand $vehicleBrand)
    {
        $this->authorizePermission($request, 'products.update');

        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:120',
                Rule::unique('vehicle_brands', 'name')->ignore($vehicleBrand->id),
            ],
            'description' => ['nullable', 'string'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $newSlug = $vehicleBrand->name !== $validated['name']
            ? $this->generateUniqueSlug($validated['name'], $vehicleBrand->id)
            : $vehicleBrand->slug;

        $vehicleBrand->update([
            'name' => $validated['name'],
            'slug' => $newSlug,
            'description' => $validated['description'] ?? null,
            'is_active' => $request->boolean('is_active', true),
        ]);

        return response()->json([
            'message' => 'Marca de vehículo actualizada correctamente.',
            'data' => $vehicleBrand->fresh()->load('models.versions'),
        ]);
    }

    public function destroy(Request $request, VehicleBrand $vehicleBrand)
    {
        $this->authorizePermission($request, 'products.delete');

        $vehicleBrand->update([
            'is_active' => false,
        ]);

        return response()->json([
            'message' => 'Marca de vehículo desactivada correctamente.',
        ]);
    }

    private function generateUniqueSlug(string $name, ?int $ignoreBrandId = null): string
    {
        $baseSlug = Str::slug($name);
        $slug = $baseSlug;
        $counter = 2;

        while (
            VehicleBrand::query()
                ->where('slug', $slug)
                ->when($ignoreBrandId, function ($query) use ($ignoreBrandId) {
                    $query->where('id', '!=', $ignoreBrandId);
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
