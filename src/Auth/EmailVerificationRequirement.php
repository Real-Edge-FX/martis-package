<?php

declare(strict_types=1);

namespace Martis\Auth;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\MustVerifyEmail;

/**
 * Whether the email verification gate holds a user (`martis.auth.email_verification`).
 */
final class EmailVerificationRequirement
{
    /**
     * Whether the workspace requires email verification AND this user
     * has not yet confirmed.
     *
     * Mirrors the resolution chain in
     * `Martis\Http\Middleware\EnsureEmailIsVerified::emailIsVerified()`
     * so the login + user endpoints gate on the same conditions as the
     * post-auth route guard. Two paths:
     *
     *   1. User implements `MustVerifyEmail` → call `hasVerifiedEmail()`.
     *   2. User exposes the `email_verified_at` column (default Laravel
     *      users table) → check it directly. Column null = pending.
     *      Column missing = treat as pending (fail-safe).
     */
    public static function requiredFor(Authenticatable $user): bool
    {
        if (! (bool) config('martis.auth.email_verification.enabled', false)) {
            return false;
        }

        if ($user instanceof MustVerifyEmail) {
            return ! $user->hasVerifiedEmail();
        }

        if (method_exists($user, 'getAttribute')) {
            $value = $user->getAttribute('email_verified_at');
            if ($value !== null) {
                return false;
            }

            if (method_exists($user, 'getAttributes')) {
                /** @var array<string, mixed> $attrs */
                $attrs = $user->getAttributes();
                if (array_key_exists('email_verified_at', $attrs)) {
                    return true;
                }
            }
        }

        // Column missing AND no contract — fail-safe so a misconfigured
        // app doesn't accidentally let unverified accounts through with
        // the flag on.
        return true;
    }
}
