<?php

namespace Martis\Auth;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Support\Facades\Log;
use Martis\Contracts\SendsEmailVerification;

/**
 * Default verification-link sender.
 *
 * Delegates to Laravel's `MustVerifyEmail` contract on the user model
 * (which dispatches the stock `Illuminate\Auth\Notifications\VerifyEmail`
 * notification through the application's mailer).
 *
 * If the consumer's user model does NOT implement `MustVerifyEmail`,
 * this is a no-op with a warning log entry — Martis intentionally does
 * not crash, since some apps disable verification entirely or wire
 * their own notification path.
 */
class DefaultSendsEmailVerification implements SendsEmailVerification
{
    public function send(Authenticatable $user): void
    {
        if (! $user instanceof MustVerifyEmail) {
            Log::warning(
                'Martis email verification: user model does not implement MustVerifyEmail; skipping send.',
                ['user_id' => method_exists($user, 'getKey') ? $user->getKey() : null]
            );

            return;
        }

        if ($user->hasVerifiedEmail()) {
            return;
        }

        $user->sendEmailVerificationNotification();
    }
}
