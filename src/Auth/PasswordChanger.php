<?php

declare(strict_types=1);

namespace Martis\Auth;

use Illuminate\Auth\Passwords\PasswordBroker;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Martis\Events\PasswordChanged;

/**
 * Sets a user's new password, for Profile and the forced password change,
 * with the hygiene of Fortify's PasswordController (behind Nova 5's User
 * Security page): the user's pending reset tokens are deleted and an event
 * is fired. Like Fortify, it neither rotates the remember token nor signs
 * other sessions out; `auth.session` (Laravel's AuthenticateSession) in
 * `martis.auth_middleware` signs them out on their next request.
 */
final class PasswordChanger
{
    public function change(Model&Authenticatable $user, string $password, bool $forced): void
    {
        $user->forceFill([$user->getAuthPasswordName() => Hash::make($password)]);
        PasswordChangeRequirement::markChanged($user);
        $user->save();

        // Only Martis's own broker issues reset tokens, and only with the
        // reset flow on (its broker is then configured).
        if (config('martis.auth.passwordReset.enabled', false) && $user instanceof CanResetPassword) {
            $broker = Password::broker(GuardCatalog::martisPasswordBroker());

            if ($broker instanceof PasswordBroker) {
                $broker->deleteToken($user);
            }
        }

        PasswordChanged::dispatch($user, $forced);
    }
}
