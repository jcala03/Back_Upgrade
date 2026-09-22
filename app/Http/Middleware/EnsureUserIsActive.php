<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $isActive = DB::table('users')
            ->where('id', $user?->getAuthIdentifier())
            ->value('is_active');

        abort_unless(
            (bool) $isActive,
            403,
            'Tu cuenta está desactivada.',
        );

        return $next($request);
    }
}
