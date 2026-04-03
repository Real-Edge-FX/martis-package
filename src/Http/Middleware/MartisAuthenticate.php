<?php

namespace Martis\Http\Middleware;

use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class MartisAuthenticate
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var string|null $guardName */
        $guardName = config('martis.guard');

        /** @var Guard $auth */
        $auth = auth()->guard($guardName);

        if ($auth->check()) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            throw new AuthenticationException('Unauthenticated.');
        }

        return redirect()->route('martis.login');
    }
}
