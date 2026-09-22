<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreBranchRequest;
use App\Http\Requests\UpdateBranchRequest;
use App\Models\Branch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class BranchController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'branches.view');
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:160'],
            'is_active' => ['nullable', Rule::in(['0', '1', 0, 1])],
            'sort' => ['nullable', Rule::in(['name', 'city', 'created_at'])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $search = trim((string) ($filters['search'] ?? ''));
        $sort = $filters['sort'] ?? 'name';
        $direction = $filters['direction'] ?? 'asc';
        $branches = Branch::query()
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $match) => $match
                ->where('code', 'like', "%{$search}%")
                ->orWhere('name', 'like', "%{$search}%")
                ->orWhere('city', 'like', "%{$search}%")))
            ->when(array_key_exists('is_active', $filters), fn (Builder $query) => $query->where('is_active', (bool) $filters['is_active']))
            ->orderBy($sort, $direction)
            ->orderBy('id')
            ->paginate((int) ($filters['per_page'] ?? 25))
            ->withQueryString();
        $branches->through(fn (Branch $branch) => $this->payload($branch));

        return response()->json(['data' => $branches]);
    }

    public function store(StoreBranchRequest $request): JsonResponse
    {
        $data = $request->validated();
        $branch = Branch::create([...$data, 'is_active' => $data['is_active'] ?? true]);

        return response()->json([
            'message' => 'Sede creada correctamente.',
            'data' => $this->payload($branch),
        ], 201);
    }

    public function show(Request $request, Branch $branch): JsonResponse
    {
        $this->authorizePermission($request, 'branches.view');

        return response()->json(['data' => $this->payload($branch)]);
    }

    public function update(UpdateBranchRequest $request, Branch $branch): JsonResponse
    {
        $data = $request->validated();
        $updated = DB::transaction(function () use ($branch, $data) {
            $locked = Branch::query()->lockForUpdate()->findOrFail($branch->id);
            if (array_key_exists('is_active', $data)
                && ! (bool) $data['is_active']
                && $locked->is_active
                && $locked->employees()->where('is_active', true)->exists()) {
                throw ValidationException::withMessages([
                    'is_active' => 'No puedes desactivar una sede con empleados activos asignados.',
                ]);
            }

            $locked->update($data);

            return $locked->fresh();
        });

        return response()->json([
            'message' => 'Sede actualizada correctamente.',
            'data' => $this->payload($updated),
        ]);
    }

    private function payload(Branch $branch): array
    {
        return $branch->only([
            'id', 'code', 'slug', 'name', 'city', 'is_active', 'created_at', 'updated_at',
            'ecommerce_priority', 'country_code', 'state', 'postal_code',
            'address_line1', 'address_line2',
        ]);
    }

    private function authorizePermission(Request $request, string $permission): void
    {
        abort_unless($request->user()?->hasPermission($permission), 403, 'No tienes permisos para realizar esta acción.');
    }
}
