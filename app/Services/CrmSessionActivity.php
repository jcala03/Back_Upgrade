<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\PersonalAccessToken;

/** One inactivity clock per existing credential, not per request or browser tab. */
class CrmSessionActivity
{
    private const SESSION_ACTIVITY = 'crm_last_interaction_at';

    public function timeoutSeconds(): int
    {
        return max(1, min(1440, (int) config('crm_session.idle_minutes', 30))) * 60;
    }

    public function start(Request $request): void
    {
        $this->write($request, now()->timestamp);
    }

    public function isActive(Request $request): bool
    {
        $key = $this->key($request);
        if ($key === null) {
            return false;
        }
        $state = Cache::get($key);
        if ($state === null) {
            // One-time adoption of a pre-release cookie session. A bearer token
            // instead starts at issuance, never at last_used_at (polling updates it).
            $initial = $this->token($request)?->created_at?->timestamp
                ?? ($request->hasSession() ? $request->session()->get(self::SESSION_ACTIVITY, now()->timestamp) : null);
            if (! is_int($initial)) {
                return false;
            }
            Cache::add($key, ['at' => $initial, 'revoked' => false], $this->retention());
            $state = Cache::get($key);
            if ($request->hasSession()) {
                $request->session()->put(self::SESSION_ACTIVITY, $initial);
            }
        }

        return is_array($state) && ($state['revoked'] ?? true) === false
            && is_int($state['at'] ?? null) && $state['at'] <= now()->timestamp
            && now()->timestamp - $state['at'] < $this->timeoutSeconds();
    }

    public function touch(Request $request): array
    {
        // The middleware must check BEFORE this method: an expired credential
        // cannot be revived by posting an interaction or changing a client date.
        $this->write($request, now()->timestamp);

        return $this->metadata($request);
    }

    public function metadata(Request $request): array
    {
        $key = $this->key($request);
        $state = $key === null ? null : Cache::get($key);

        return [
            'session_id' => $key === null ? null : hash('sha256', $key),
            'idle_timeout_seconds' => $this->timeoutSeconds(),
            'server_time' => now()->timestamp,
            'expires_at' => (int) ($state['at'] ?? 0) + $this->timeoutSeconds(),
        ];
    }

    public function end(Request $request): void
    {
        if (($key = $this->key($request)) !== null) {
            // Prevent in-flight requests from reviving an invalidated session ID.
            Cache::put($key, ['at' => now()->timestamp, 'revoked' => true], $this->retention());
        }
        $this->token($request)?->delete();
        if ($request->hasSession()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }
    }

    private function write(Request $request, int $timestamp): void
    {
        if (($key = $this->key($request)) === null) {
            return;
        }
        Cache::put($key, ['at' => $timestamp, 'revoked' => false], $this->retention());
        if ($request->hasSession() && $this->token($request) === null) {
            $request->session()->put(self::SESSION_ACTIVITY, $timestamp);
        }
    }

    private function key(Request $request): ?string
    {
        $token = $this->token($request);
        $credential = $token ? 'token:'.$token->getKey()
            : ($request->hasSession() ? 'session:'.$request->session()->getId() : null);

        return $credential === null ? null : 'crm-idle:'.hash('sha256', $credential);
    }

    private function token(Request $request): ?PersonalAccessToken
    {
        $token = $request->user()?->currentAccessToken();

        return $token instanceof PersonalAccessToken && $token->getKey() !== null ? $token : null;
    }

    private function retention(): int
    {
        return max($this->timeoutSeconds(), (int) config('session.lifetime', 120) * 60) + 3600;
    }
}
