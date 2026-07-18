<?php

namespace Martis\Http\Controllers\Concerns;

use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\Request;
use Martis\Http\Controllers\AuthController;
use Martis\Http\Controllers\LoginController;

/**
 * Shared "Keep me signed in on this device" handling for the two login
 * entry points (the SPA {@see AuthController::login}
 * and the non-SPA {@see LoginController::login}).
 *
 * Both route their credential validation and authentication attempt through
 * here so the remember-me handling cannot diverge again: historically the SPA
 * controller omitted the `$remember` argument entirely, silently disabling the
 * default-checked toggle on the only path the React app uses.
 */
trait AuthenticatesWithRememberMe
{
    /**
     * Validation rules shared by both login endpoints. `keep_signed_in` is
     * optional (absent means "off") and drives Laravel's remember-me cookie.
     *
     * @return array<string, list<string>>
     */
    protected function loginRules(): array
    {
        return [
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'keep_signed_in' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Attempt authentication, forwarding the "Keep me signed in" toggle as the
     * `$remember` flag so Laravel issues the long-lived remember-me cookie.
     * Call only after validating the request with {@see loginRules()}.
     */
    protected function attemptLogin(StatefulGuard $auth, Request $request): bool
    {
        /** @var array<string, mixed> $credentials */
        $credentials = $request->only(['email', 'password']);

        return $auth->attempt($credentials, $request->boolean('keep_signed_in'));
    }
}
