<?php

namespace Martis\Auth;

use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Martis\Contracts\ResetsUserPasswords;

/**
 * Default reset-password handler.
 *
 * Calls Laravel's `Password::reset()` with the broker configured under
 * `martis.auth.passwordReset.broker`. On success the user's password is
 * hashed and persisted, the remember_token is rotated, and
 * `Illuminate\Auth\Events\PasswordReset` is fired so listeners (e.g.
 * "all your sessions were signed out" notifications) can react.
 */
class DefaultResetsUserPasswords implements ResetsUserPasswords
{
    public function reset(Request $request): string
    {
        $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'confirmed', PasswordRule::min(8)],
        ]);

        $broker = (string) config('martis.auth.passwordReset.broker', 'users');

        return Password::broker($broker)->reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (Model&Authenticatable $user, string $password): void {
                $user->forceFill([
                    'password' => Hash::make($password),
                    'remember_token' => Str::random(60),
                ])->save();

                event(new PasswordReset($user));
            }
        );
    }
}
