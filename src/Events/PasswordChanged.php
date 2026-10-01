<?php

namespace Martis\Events;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Dispatched after a user chose a new password in the panel: from Profile
 * (`forced` false) or on the forced password change page (`forced` true).
 * Nova 5's User Security page fires Fortify's PasswordUpdatedViaController
 * at the same point.
 *
 * Usage:
 *   Event::listen(PasswordChanged::class, function (PasswordChanged $event) {
 *       AuditLog::record('password.changed', $event->user, ['forced' => $event->forced]);
 *   });
 */
class PasswordChanged
{
    use Dispatchable;

    /** Create the PasswordChanged event. */
    public function __construct(
        /** The user whose password changed. */
        public readonly Authenticatable $user,

        /** True when the forced password change gate asked for it. */
        public readonly bool $forced,
    ) {}
}
