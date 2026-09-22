<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreServiceCategoryRequest;
use App\Http\Requests\UpdateServiceCategoryRequest;
use App\Models\ServiceCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ServiceCategoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'services.view');
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:160'],
            'is_active' => ['nullable', Rule::in(['0', '1', 0, 1])],
            'sort' => ['nullable', Rule::in(['sort_order', 'name', 'created_at'])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $search = trim((string) ($filters['search'] ?? ''));
        $sort = $filters['sort'] ?? 'sort_order';
        $direction = $filters['direction'] ?? 'asc';
        $query = ServiceCategory::query()->withCount('services')
            ->when($search !== '', fn ($query) => $query->where('name', 'like', "%{$search}%"))
            ->when(array_key_exists('is_active', $filters), fn ($query) => $query->where('is_active', (bool) $filters['is_active']))
            ->orderBy($sort, $direction);
        if ($sort !== 'name') {
            $query->orderBy('name');
        }

        return response()->json(['data' => $query->orderBy('id')->paginate((int) ($filters['per_page'] ?? 25))->withQueryString()]);
    }

    public function store(StoreServiceCategoryRequest $request): JsonResponse
    {
        $data = $request->validated();
        $category = ServiceCategory::create([
            ...$data,
            'slug' => $this->uniqueSlug($data['name']),
            'is_active' => $data['is_active'] ?? true,
            'sort_order' => $data['sort_order'] ?? 0,
        ]);

        return response()->json(['message' => 'Categoría de servicio creada correctamente.', 'data' => $category->loadCount('services')], 201);
    }

    public function update(UpdateServiceCategoryRequest $request, ServiceCategory $serviceCategory): JsonResponse
    {
        $data = $request->validated();
        if (isset($data['name']) && $data['name'] !== $serviceCategory->name) {
            $data['slug'] = $this->uniqueSlug($data['name'], $serviceCategory->id);
        }
        $serviceCategory->update($data);

        return response()->json(['message' => 'Categoría de servicio actualizada correctamente.', 'data' => $serviceCategory->fresh()->loadCount('services')]);
    }

    public function destroy(Request $request, ServiceCategory $serviceCategory): JsonResponse
    {
        $this->authorizePermission($request, 'services.delete');
        $serviceCategory->update(['is_active' => false]);

        return response()->json(['message' => 'Categoría de servicio desactivada correctamente.', 'data' => $serviceCategory->fresh()->loadCount('services')]);
    }

    private function uniqueSlug(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name) ?: 'categoria-servicio';
        $slug = $base;
        $suffix = 2;
        while (ServiceCategory::query()->where('slug', $slug)->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))->exists()) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }

    private function authorizePermission(Request $request, string $permission): void
    {
        abort_unless($request->user()?->hasPermission($permission), 403, 'No tienes permisos para realizar esta acción.');
    }
}
