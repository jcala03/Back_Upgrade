<?php

namespace App\Http\Middleware;

use App\Services\CrmSessionActivity;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureCrmSessionIsActive
{
    public function __construct(private readonly CrmSessionActivity $activity) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->activity->isActive($request)) {
            $this->activity->end($request);

            return response()->json([
                'message' => 'Tu sesión terminó por inactividad. Inicia sesión nuevamente.',
                'code' => 'SESSION_EXPIRED',
            ], 401);
        }

        return $next($request);
    }
}
