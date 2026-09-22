<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\VehicleMultimediaSystem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class VehicleMultimediaSystemController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $systems = VehicleMultimediaSystem::query()
            ->with(['vehicleBrand', 'vehicleVersions.model.brand'])
            ->where('is_active', true)
            ->when($request->filled('vehicle_brand_id'), function (Builder $query) use ($request) {
                $query->where('vehicle_brand_id', $request->integer('vehicle_brand_id'));
            })
            ->orderBy('vehicle_brand_id')
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $systems,
        ]);
    }

    public function adminIndex(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'products.view');

        $systems = VehicleMultimediaSystem::query()
            ->with(['vehicleBrand', 'vehicleVersions.model.brand'])
            ->when($request->filled('vehicle_brand_id'), function (Builder $query) use ($request) {
                $query->where('vehicle_brand_id', $request->integer('vehicle_brand_id'));
            })
            ->when($request->filled('search'), function (Builder $query) use ($request) {
                $search = trim((string) $request->input('search'));

                $query->where(function (Builder $subQuery) use ($search) {
                    $subQuery
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('code', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%");
                });
            })
            ->orderBy('vehicle_brand_id')
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $systems,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'products.create');

        $validated = $this->validateSystem($request);

        $system = VehicleMultimediaSystem::create([
            'vehicle_brand_id' => $validated['vehicle_brand_id'],
            'name' => $validated['name'],
            'slug' => $this->generateUniqueSlug(
                $validated['name'],
                (int) $validated['vehicle_brand_id']
            ),
            'code' => $this->nullableTrim($validated['code'] ?? null),
            'description' => $this->nullableTrim($validated['description'] ?? null),
            'is_active' => $this->parseBooleanValue($validated['is_active'] ?? true),
        ]);

        return response()->json([
            'message' => 'Sistema multimedia creado correctamente.',
            'data' => $system->fresh('vehicleBrand'),
        ], 201);
    }

    public function update(
        Request $request,
        VehicleMultimediaSystem $vehicleMultimediaSystem
    ): JsonResponse {
        $this->authorizePermission($request, 'products.update');

        $validated = $this->validateSystem($request, $vehicleMultimediaSystem);

        $vehicleBrandId = (int) $validated['vehicle_brand_id'];
        $name = $validated['name'];

        $vehicleMultimediaSystem->update([
            'vehicle_brand_id' => $vehicleBrandId,
            'name' => $name,
            'slug' => $this->generateUniqueSlug(
                $name,
                $vehicleBrandId,
                $vehicleMultimediaSystem->id
            ),
            'code' => $this->nullableTrim($validated['code'] ?? null),
            'description' => $this->nullableTrim($validated['description'] ?? null),
            'is_active' => $this->parseBooleanValue(
                $validated['is_active'] ?? $vehicleMultimediaSystem->is_active
            ),
        ]);

        return response()->json([
            'message' => 'Sistema multimedia actualizado correctamente.',
            'data' => $vehicleMultimediaSystem->fresh('vehicleBrand'),
        ]);
    }

    public function destroy(Request $request, VehicleMultimediaSystem $vehicleMultimediaSystem): JsonResponse
    {
        $this->authorizePermission($request, 'products.delete');

        $vehicleMultimediaSystem->delete();

        return response()->json([
            'message' => 'Sistema multimedia eliminado correctamente.',
        ]);
    }

    private function validateSystem(
        Request $request,
        ?VehicleMultimediaSystem $system = null
    ): array {
        $vehicleBrandId = (int) $request->input(
            'vehicle_brand_id',
            $system?->vehicle_brand_id
        );

        return $request->validate([
            'vehicle_brand_id' => ['required', 'integer', 'exists:vehicle_brands,id'],
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('vehicle_multimedia_systems', 'name')
                    ->where(function ($query) use ($vehicleBrandId) {
                        $query->where('vehicle_brand_id', $vehicleBrandId);
                    })
                    ->ignore($system?->id),
            ],
            'code' => ['nullable', 'string', 'max:80'],
            'description' => ['nullable', 'string'],
            'is_active' => ['nullable'],
        ]);
    }

    private function generateUniqueSlug(
        string $name,
        int $vehicleBrandId,
        ?int $ignoreId = null
    ): string {
        $baseSlug = Str::slug($name);
        $slug = $baseSlug;
        $counter = 2;

        while (
            VehicleMultimediaSystem::query()
                ->where('vehicle_brand_id', $vehicleBrandId)
                ->where('slug', $slug)
                ->when($ignoreId, function (Builder $query) use ($ignoreId) {
                    $query->whereKeyNot($ignoreId);
                })
                ->exists()
        ) {
            $slug = "{$baseSlug}-{$counter}";
            $counter++;
        }

        return $slug;
    }

    private function nullableTrim(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    private function parseBooleanValue(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value === 1;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    private function authorizePermission(Request $request, string $permission): void
    {
        $user = $request->user();

        if (! $user || ! $user->hasPermission($permission)) {
            abort(403, 'No tienes permiso para realizar esta acción.');
        }
    }
}
