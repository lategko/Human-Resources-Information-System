<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAccountSetupIsComplete
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->isAdministrator()
            && ($request->user()?->must_change_password || ! $request->user()?->account_setup_completed)) {
            return redirect()->route('account.setup');
        }

        return $next($request);
    }
}
