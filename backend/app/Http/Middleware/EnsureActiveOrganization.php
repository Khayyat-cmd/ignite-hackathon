<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveOrganization
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        abort_unless($user && $user->status === 'active', 403, 'This account is not active.');
        abort_unless($user->organization_id && $user->organization?->status === 'active', 403, 'This organization is awaiting approval or disabled.');

        return $next($request);
    }
}
