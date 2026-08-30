<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RequireAbility
{
    public function handle(Request $request, Closure $next, string $ability): Response
    {
        abort_unless($request->user()?->tokenCan($ability), 403, 'Token lacks the required permission.');

        return $next($request);
    }
}
