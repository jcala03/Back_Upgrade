<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdatePasswordRequest;
use App\Http\Requests\UpdateProfileRequest;
use App\Models\PushSubscription;
use App\Models\User;
use App\Services\CrmSessionActivity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt($credentials)) {
            throw ValidationException::withMessages([
                'email' => ['Las credenciales no son correctas.'],
            ]);
        }

        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        /** @var User $user */
        $user = $request->user();

        if (! $user->is_active) {
            Auth::guard('web')->logout();

            if ($request->hasSession()) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }

            throw ValidationException::withMessages([
                'email' => ['Este usuario está desactivado.'],
            ]);
        }

        app(CrmSessionActivity::class)->start($request);

        return response()->json([
            'message' => 'Login correcto.',
            'user' => $this->userPayload($user),
        ]);
    }

    public function me(Request $request)
    {
        return response()->json([
            'user' => $this->userPayload($request->user()),
        ]);
    }

    public function sessionStatus(Request $request)
    {
        return response()->json(['session' => app(CrmSessionActivity::class)->metadata($request)]);
    }

    public function activity(Request $request)
    {
        return response()->json(['session' => app(CrmSessionActivity::class)->touch($request)]);
    }

    public function updateProfile(UpdateProfileRequest $request)
    {
        $request->user()->update($request->validated());

        return response()->json([
            'message' => 'Perfil actualizado correctamente.',
            'user' => $this->userPayload($request->user()->fresh()),
        ]);
    }

    public function updatePassword(UpdatePasswordRequest $request)
    {
        $request->user()->update(['password' => $request->validated('password')]);

        return response()->json(['message' => 'Contraseña actualizada correctamente.']);
    }

    public function logout(Request $request)
    {
        if ($request->has('idle_session_id')) {
            $request->validate(['idle_session_id' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/']]);
            $id = app(CrmSessionActivity::class)->metadata($request)['session_id'];
            if (! is_string($id) || ! hash_equals($id, $request->input('idle_session_id'))) {
                return response()->json(['message' => 'La sesión cambió. Recarga la página.'], 409);
            }
        }
        // Only the current browser; other devices belonging to this account remain subscribed.
        if (is_string($endpoint = $request->input('push_endpoint')) && strlen($endpoint) <= 2048) {
            PushSubscription::where('user_id', $request->user()->id)
                ->where('endpoint_hash', hash('sha256', $endpoint))->update(['is_active' => false]);
        }
        app(CrmSessionActivity::class)->end($request);

        return response()->json([
            'message' => 'Sesión cerrada correctamente.',
        ]);
    }

    private function userPayload(User $user): array
    {
        $user->loadMissing([
            'employee' => fn ($query) => $query->select([
                'id', 'user_id', 'branch_id', 'name', 'is_active',
            ]),
            'employee.branch' => fn ($query) => $query->select([
                'id', 'code', 'name', 'city', 'is_active',
            ]),
        ]);
        $employee = $user->employee;

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'permissions' => $user->permissions(),
            'employee' => $employee ? [
                ...$employee->only([
                    'id', 'branch_id', 'name', 'is_active',
                ]),
                'branch' => $employee->branch?->only([
                    'id', 'code', 'name', 'city', 'is_active',
                ]),
            ] : null,
        ];
    }
}
