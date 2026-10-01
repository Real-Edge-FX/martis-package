<?php

declare(strict_types=1);

namespace Martis\Auth\Listeners;

use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Database\Eloquent\Model;
use Martis\Auth\PasswordChangeRequirement;

/**
 * A password reset by email clears the forced password change flag: the
 * user chose a password the operator does not know. Listening to the
 * framework event keeps working when an app rebinds ResetsUserPasswords, as
 * long as its implementation fires PasswordReset (the default one does).
 */
final class ClearPasswordChangeRequirement
{
    public function handle(PasswordReset $event): void
    {
        $user = $event->user;

        if (! PasswordChangeRequirement::flagged($user)) {
            return;
        }

        PasswordChangeRequirement::markChanged($user);

        if ($user instanceof Model) {
            $user->save();
        }
    }
}
