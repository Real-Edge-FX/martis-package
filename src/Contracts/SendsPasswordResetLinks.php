<?php

namespace Martis\Contracts;

use Illuminate\Http\Request;

/**
 * Contract for the "email me a reset link" half of the password reset flow.
 *
 * Martis ships `Martis\Auth\DefaultSendsPasswordResetLinks` which delegates
 * to Laravel's `Password::sendResetLink()` against the broker named in
 * `config('martis.auth.passwordReset.broker', 'users')`.
 *
 * Override the binding in a consumer service provider to plug in custom
 * delivery (e.g. queueing, magic-link tokens, branded notifications):
 *
 * ```php
 * $this->app->bind(
 *     \Martis\Contracts\SendsPasswordResetLinks::class,
 *     \App\Auth\BrandedResetLinkSender::class,
 * );
 * ```
 */
interface SendsPasswordResetLinks
{
    /**
     * Send a password reset link to the e-mail in the request body.
     *
     * Returns one of the `Illuminate\Support\Facades\Password` status
     * constants (`RESET_LINK_SENT`, `INVALID_USER`, etc.) so the
     * Martis-shipped controller can map it to the correct HTTP response.
     */
    public function sendResetLink(Request $request): string;
}
