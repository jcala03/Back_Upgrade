<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreServiceRequest;
use App\Http\Requests\UpdateServiceRequest;
use App\Models\Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ServiceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'services.view');
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:160'],
            'service_category_id' => ['nullable', 'integer', 'exists:service_categories,id'],
            'is_active' => ['nullable', Rule::in(['0', '1', 0, 1])],
            'sort' => ['nullable', Rule::in(['sort_order', 'name', 'price', 'estimated_duration_minutes', 'created_at'])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $search = trim((string) ($filters['search'] ?? ''));
        $sort = $filters['sort'] ?? 'sort_order';
        $direction = $filters['direction'] ?? 'asc';
        $query = Service::query()->with('category:id,name,slug,is_active,sort_order')
            ->when($search !== '', fn ($query) => $query->where(fn ($match) => $match->where('name', 'like', "%{$search}%")->orWhere('description', 'like', "%{$search}%")))
            ->when($filters['service_category_id'] ?? null, fn ($query, $id) => $query->where('service_category_id', $id))
            ->when(array_key_exists('is_active', $filters), fn ($query) => $query->where('is_active', (bool) $filters['is_active']))
            ->orderBy($sort, $direction);
        if ($sort !== 'name') {
            $query->orderBy('name');
        }

        return response()->json(['data' => $query->orderBy('id')->paginate((int) ($filters['per_page'] ?? 25))->withQueryString()]);
    }

    public function store(StoreServiceRequest $request): JsonResponse
    {
        $data = $request->validated();
        $service = Service::create([
            ...$data,
            'slug' => $this->uniqueSlug($data['name']),
            'is_active' => $data['is_active'] ?? true,
            'sort_order' => $data['sort_order'] ?? 0,
        ]);

        return response()->json(['message' => 'Servicio creado correctamente.', 'data' => $service->load('category')], 201);
    }

    public function show(Request $request, Service $service): JsonResponse
    {
        $this->authorizePermission($request, 'services.view');

        return response()->json(['data' => $service->load('category')]);
    }

    public function update(UpdateServiceRequest $request, Service $service): JsonResponse
    {
        $data = $request->validated();
        if (isset($data['name']) && $data['name'] !== $service->name) {
            $data['slug'] = $this->uniqueSlug($data['name'], $service->id);
        }
        $service->update($data);

        return response()->json(['message' => 'Servicio actualizado correctamente.', 'data' => $service->fresh()->load('category')]);
    }

    public function destroy(Request $request, Service $service): JsonResponse
    {
        $this->authorizePermission($request, 'services.delete');
        $service->update(['is_active' => false]);

        return response()->json(['message' => 'Servicio desactivado correctamente.', 'data' => $service->fresh()->load('category')]);
    }

    private function uniqueSlug(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name) ?: 'servicio';
        $slug = $base;
        $suffix = 2;
        while (Service::query()->where('slug', $slug)->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))->exists()) {
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
