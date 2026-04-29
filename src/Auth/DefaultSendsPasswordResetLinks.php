<?php

namespace Martis\Auth;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Martis\Contracts\SendsPasswordResetLinks;

/**
 * Default "email me a reset link" handler.
 *
 * Delegates to Laravel's `Password::sendResetLink()` against the broker
 * named in `config('martis.auth.passwordReset.broker', 'users')`. The
 * broker is responsible for hashing the token and dispatching the
 * notification through whichever mailer the host app has configured.
 */
class DefaultSendsPasswordResetLinks implements SendsPasswordResetLinks
{
    public function sendResetLink(Request $request): string
    {
        $request->validate([
            'email' => ['required', 'email'],
        ]);

        $broker = (string) config('martis.auth.passwordReset.broker', 'users');

        return Password::broker($broker)->sendResetLink($request->only('email'));
    }
}
