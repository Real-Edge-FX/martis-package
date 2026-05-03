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

        if ($user === null || ! $this->emailIsVerified($user)) {
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

    /**
     * Whether the user has a verified email.
     *
     * Two paths:
     *   1. User implements `MustVerifyEmail` (Laravel's recommended
     *      contract) → call `hasVerifiedEmail()` verbatim.
     *   2. User does NOT implement the contract but exposes the
     *      `email_verified_at` attribute (the standard Laravel users
     *      table ships this column) → check it directly. This is the
     *      common-case fallback: most app User models keep the column
     *      from the default migration but never opt into the trait.
     *      Treating them as silently verified would defeat the entire
     *      flag — Martis chooses to enforce verification consistently
     *      with the column when available.
     *
     * If neither path applies, the user is considered unverified —
     * fail-safe so a misconfigured app doesn't accidentally let
     * unverified accounts through with the flag on.
     */
    protected function emailIsVerified(mixed $user): bool
    {
        if ($user instanceof MustVerifyEmail) {
            return $user->hasVerifiedEmail();
        }

        if (is_object($user) && method_exists($user, 'getAttribute')) {
            $value = $user->getAttribute('email_verified_at');
            if ($value !== null) {
                return true;
            }

            // Distinguish "column null" from "column missing": when
            // the column doesn't exist on the model, getAttribute
            // also returns null. Use the model's attribute set so we
            // only block when the column actually exists and is null.
            if (method_exists($user, 'getAttributes')) {
                /** @var array<string, mixed> $attrs */
                $attrs = $user->getAttributes();
                if (array_key_exists('email_verified_at', $attrs)) {
                    return false;
                }
            }
        }

        // No way to tell — fail safe so the flag's intent wins.
        return false;
    }
}
