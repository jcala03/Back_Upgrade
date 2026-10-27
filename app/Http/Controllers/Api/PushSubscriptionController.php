<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PushSubscription;
use App\Services\CrmWebPush;
use App\Support\PushEndpoint;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PushSubscriptionController extends Controller
{
    private function authorizeUser(Request $request): void
    {
        abort_unless($request->user()->hasPermission('notifications.view'), 403);
    }

    public function config(Request $request)
    {
        $this->authorizeUser($request);

        return response()->json(['data' => [
            'enabled' => CrmWebPush::configured(),
            'public_key' => CrmWebPush::configured() ? config('webpush.public_key') : null,
        ]])->header('Cache-Control', 'no-store');
    }

    public function index(Request $request)
    {
        $this->authorizeUser($request);

        return response()->json(['data' => PushSubscription::where('user_id', $request->user()->id)
            ->get(['id', 'endpoint_hash', 'is_active', 'expires_at'])])
            ->header('Cache-Control', 'no-store');
    }

    public function store(Request $request)
    {
        $this->authorizeUser($request);
        abort_unless(CrmWebPush::configured(), 503, 'Las notificaciones Push todavía no están configuradas.');
        $data = $request->validate([
            'user_id' => ['prohibited'],
            'endpoint' => ['bail', 'required', 'string', 'max:2048', function ($attribute, $value, $fail) {
                if (! PushEndpoint::valid($value)) {
                    $fail('El endpoint debe pertenecer a un servicio Web Push compatible y seguro.');
                }
            }],
            'keys' => ['required', 'array:p256dh,auth'],
            'keys.p256dh' => ['required', 'string', 'max:100'],
            'keys.auth' => ['required', 'string', 'max:30'],
            'expirationTime' => ['nullable', 'integer', 'min:1', 'max:2147483647000'],
        ]);
        foreach (['p256dh' => 65, 'auth' => 16] as $key => $length) {
            if (! PushEndpoint::keyValid($data['keys'][$key], $length)) {
                throw ValidationException::withMessages(["keys.$key" => 'La clave de suscripción no es válida.']);
            }
        }
        $hash = hash('sha256', $data['endpoint']);
        $existing = PushSubscription::where('endpoint_hash', $hash)->first();
        abort_if($existing && (int) $existing->user_id !== $request->user()->id, 409, 'Este dispositivo debe renovar su suscripción.');
        $expiration = isset($data['expirationTime']) ? Carbon::createFromTimestampMs($data['expirationTime']) : null;
        if ($expiration?->isPast()) {
            throw ValidationException::withMessages(['expirationTime' => 'La suscripción ya expiró.']);
        }
        $values = [
            'user_id' => $request->user()->id, 'endpoint_hash' => $hash,
            'endpoint' => $data['endpoint'], 'p256dh' => $data['keys']['p256dh'], 'auth' => $data['keys']['auth'],
            'is_active' => true, 'expires_at' => $expiration,
        ];
        try {
            $subscription = PushSubscription::updateOrCreate(['endpoint_hash' => $hash, 'user_id' => $request->user()->id], $values);
        } catch (QueryException $exception) {
            // A concurrently registered endpoint must never be transferred to another account.
            if (PushSubscription::where('endpoint_hash', $hash)->where('user_id', '!=', $request->user()->id)->exists()) {
                abort(409, 'Este dispositivo debe renovar su suscripción.');
            }
            throw $exception;
        }

        return response()->json(['data' => $subscription->only(['id', 'endpoint_hash', 'is_active', 'expires_at'])], 201)
            ->header('Cache-Control', 'no-store');
    }

    public function destroy(Request $request, int $subscription)
    {
        $this->authorizeUser($request);
        PushSubscription::where('user_id', $request->user()->id)->findOrFail($subscription)->update(['is_active' => false]);

        return response()->json(['message' => 'Notificaciones desactivadas en este dispositivo.']);
    }
}
