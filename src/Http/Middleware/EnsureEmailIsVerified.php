<?php

namespace Martis\Http\Middleware;

use Closure;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Mirror of Illuminate\Auth\Middleware\EnsureEmailIsVerified with two
 * Martis-specific tweaks:
 *
 *   1. The redirect target comes from
 *      `martis.auth.email_verification.notice_url` (defaults to the
 *      Martis-themed `/{martis-path}/email/verify` page).
 *
 *   2. When the global flag `martis.auth.email_verification.enabled`
 *      is false, the middleware passes through untouched. Consumers
 *      that want verification only on a subset of routes can simply
 *      skip the alias instead of having to write a separate guard.
 *
 * Registered as the `martis.verified` middleware alias by the
 * Martis service provider.
 */
class EnsureEmailIsVerified
{
    public function handle(Request $request, Closure $next): Response|RedirectResponse|JsonResponse
    {
        if (! config('martis.auth.email_verification.enabled', false)) {
            return $next($request);
        }

        $user = $request->user();

        if (
            $user === null
            || (
                $user instanceof MustVerifyEmail
                && ! $user->hasVerifiedEmail()
            )
        ) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'Your email address is not verified.',
                ], 409);
            }

            $martisPath = trim((string) config('martis.path', 'martis'), '/');
            $defaultUrl = '/'.($martisPath !== '' ? $martisPath.'/' : '').'email/verify';

            $configured = config('martis.auth.email_verification.notice_url');
            $target = is_string($configured) && $configured !== '' ? $configured : $defaultUrl;

            return redirect($target);
        }

        return $next($request);
    }
}
