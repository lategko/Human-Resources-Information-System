<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();
        $hasRole = $user && (
            in_array($user->role, $roles, true)
            || ($user->isSuperAdmin() && in_array('admin', $roles, true))
        );

        abort_unless($hasRole, 403);

        return $next($request);
    }
}
