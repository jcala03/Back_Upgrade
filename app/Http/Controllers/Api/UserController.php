<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserCapabilitiesRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Models\User;
use App\Models\UserCapability;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'users.view');
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:160'],
            'role' => ['nullable', 'in:'.implode(',', User::roles())],
            'is_active' => ['nullable', 'boolean'],
            'sort' => ['nullable', 'in:name,email,role,created_at'],
            'direction' => ['nullable', 'in:asc,desc'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $search = trim((string) ($filters['search'] ?? ''));
        $users = User::query()
            ->select(['id', 'name', 'email', 'role', 'is_active', 'created_at', 'updated_at'])
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $match) => $match
                ->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")))
            ->when($filters['role'] ?? null, fn (Builder $query, string $role) => $query->where('role', $role))
            ->when(array_key_exists('is_active', $filters), fn (Builder $query) => $query->where('is_active', $filters['is_active']))
            ->orderBy($filters['sort'] ?? 'created_at', $filters['direction'] ?? 'desc')
            ->orderBy('id')
            ->paginate((int) ($filters['per_page'] ?? 25))
            ->withQueryString();

        return response()->json(['data' => $users]);
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $data = $request->validated();
        $user = User::create([
            ...$data,
            'is_active' => $data['is_active'] ?? true,
        ]);

        return response()->json(['message' => 'Usuario creado correctamente.', 'data' => $this->payload($user)], 201);
    }

    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        $data = $request->validated();
        if (array_key_exists('role', $data) && ! $request->user()->hasPermission('roles.manage')) {
            abort(403, 'No tienes permisos para cambiar roles.');
        }
        if ((int) $request->user()->id === (int) $user->id) {
            if (array_key_exists('role', $data) || (array_key_exists('is_active', $data) && ! $data['is_active'])) {
                throw ValidationException::withMessages(['user' => 'No puedes cambiar tu propio rol ni desactivar tu cuenta.']);
            }
            if (array_key_exists('name', $data) || array_key_exists('email', $data)) {
                throw ValidationException::withMessages(['user' => 'Usa Mi Perfil para modificar tus propios datos.']);
            }
        }

        $updated = DB::transaction(function () use ($user, $data) {
            $locked = User::query()->lockForUpdate()->findOrFail($user->id);
            if ($locked->role === User::ROLE_ADMIN && $locked->is_active) {
                $removesActiveAdmin = ($data['role'] ?? User::ROLE_ADMIN) !== User::ROLE_ADMIN
                    || (array_key_exists('is_active', $data) && ! $data['is_active']);
                if ($removesActiveAdmin) {
                    User::query()->where('role', User::ROLE_ADMIN)->where('is_active', true)->lockForUpdate()->get(['id']);
                    if (User::query()->where('role', User::ROLE_ADMIN)->where('is_active', true)->count() <= 1) {
                        throw ValidationException::withMessages(['user' => 'Debe permanecer al menos un administrador activo.']);
                    }
                }
            }
            $locked->update($data);

            if ($locked->wasChanged('is_active') && ! $locked->is_active) {
                $this->revokeAuthentication($locked);
            }

            if ($locked->role !== User::ROLE_USER) {
                $locked->capabilities()->delete();
            }

            return $locked->fresh();
        });

        return response()->json(['message' => 'Usuario actualizado correctamente.', 'data' => $this->payload($updated)]);
    }

    public function capabilities(Request $request, User $user): JsonResponse
    {
        $this->authorizePermission($request, 'users.update');
        $this->requireCapabilityTarget($user);

        return response()->json([
            'data' => $this->capabilityPayload($user),
        ]);
    }

    public function replaceCapabilities(UpdateUserCapabilitiesRequest $request, User $user): JsonResponse
    {
        $requested = $request->validated('capabilities');
        $actor = $request->user();

        $updated = DB::transaction(function () use ($user, $requested, $actor) {
            $locked = User::query()->lockForUpdate()->findOrFail($user->id);
            $this->requireCapabilityTarget($locked);

            if ($requested === []) {
                $locked->capabilities()->delete();
            } else {
                $locked->capabilities()
                    ->whereNotIn('capability', $requested)
                    ->delete();
            }

            $existing = $locked->capabilities()
                ->whereIn('capability', $requested)
                ->pluck('capability')
                ->all();

            foreach (array_diff($requested, $existing) as $capability) {
                $locked->capabilities()->create([
                    'capability' => $capability,
                    'granted_by' => $actor->id,
                ]);
            }

            return $locked->fresh();
        });

        return response()->json([
            'message' => 'Capabilities actualizadas correctamente.',
            'data' => $this->capabilityPayload($updated),
        ]);
    }

    private function payload(User $user): array
    {
        return $user->only(['id', 'name', 'email', 'role', 'is_active', 'created_at', 'updated_at']);
    }

    private function capabilityPayload(User $user): array
    {
        $granted = $user->capabilities()
            ->pluck('capability')
            ->flip();

        return [
            'user' => $user->only(['id', 'name', 'email', 'role', 'is_active']),
            'capabilities' => array_values(array_filter(
                UserCapability::allowed(),
                fn (string $capability): bool => $granted->has($capability)
            )),
            'allowed_capabilities' => UserCapability::allowed(),
        ];
    }

    private function requireCapabilityTarget(User $user): void
    {
        if ($user->role !== User::ROLE_USER) {
            throw ValidationException::withMessages([
                'user' => 'Las capabilities individuales solo pueden asignarse a usuarios con rol user.',
            ]);
        }
    }

    private function authorizePermission(Request $request, string $permission): void
    {
        abort_unless($request->user()?->hasPermission($permission), 403, 'No tienes permisos para realizar esta acción.');
    }

    private function revokeAuthentication(User $user): void
    {
        $user->tokens()->delete();

        if (config('session.driver') !== 'database') {
            return;
        }

        DB::connection(config('session.connection'))
            ->table((string) config('session.table', 'sessions'))
            ->where('user_id', $user->id)
            ->delete();
    }
}
