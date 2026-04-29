<?php

namespace Martis\Contracts;

use Illuminate\Http\Request;

/**
 * Contract for the "use this token to set a new password" half of the
 * password reset flow.
 *
 * Martis ships `Martis\Auth\DefaultResetsUserPasswords` which delegates to
 * Laravel's `Password::reset()` against the broker named in
 * `config('martis.auth.passwordReset.broker', 'users')` and dispatches
 * `Illuminate\Auth\Events\PasswordReset` on success.
 *
 * Override the binding to enforce extra invariants (audit log, force
 * 2FA re-arm, invalidate other sessions, etc.).
 */
interface ResetsUserPasswords
{
    /**
     * Apply the new password and return one of the `Password::PASSWORD_RESET`
     * / `INVALID_TOKEN` / `INVALID_USER` status constants.
     */
    public function reset(Request $request): string;
}
