<?php

namespace Martis\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Contract for "send the verification link to this user".
 *
 * Default impl `Martis\Auth\DefaultSendsEmailVerification` checks if
 * the user model implements `Illuminate\Contracts\Auth\MustVerifyEmail`
 * and calls `sendEmailVerificationNotification()`. The contract gives
 * consumers a single override point to plug branded mailers, queued
 * dispatch, magic-link tokens, or anything else.
 *
 * Bind your own implementation in your service provider:
 *
 * ```php
 * $this->app->bind(
 *     \Martis\Contracts\SendsEmailVerification::class,
 *     \App\Auth\BrandedVerificationMailer::class,
 * );
 * ```
 */
interface SendsEmailVerification
{
    public function send(Authenticatable $user): void;
}
