<?php

declare(strict_types=1);

namespace Martis\Auth;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;

/**
 * Who may open the panel at all: the `viewMartis` gate (v2.1.0).
 *
 * Optional. An app that does not define the gate lets every signed-in user
 * in, as before; an app that defines it decides per user, in every
 * environment. Nova's `viewNova` is skipped in `local` because its gate
 * ships closed; this one ships open, so a bypass would only make the
 * restriction impossible to try locally.
 *
 * The user is never passed as a gate argument, so a policy of the user
 * model cannot intercept the check (laravel/nova-issues#5909);
 * `Gate::before()` callbacks apply as usual.
 */
final class PanelAccess
{
    public const GATE = 'viewMartis';

    public static function allows(?Authenticatable $user): bool
    {
        if (! Gate::has(self::GATE)) {
            return true;
        }

        if ($user === null) {
            return false;
        }

        return Gate::forUser($user)->check(self::GATE);
    }
}
