<?php

declare(strict_types=1);

namespace Martis\Auth\Listeners;

use Illuminate\Auth\Events\Login;
use Illuminate\Contracts\Session\Session;
use Martis\Auth\GuardCatalog;
use Martis\Auth\TwoFactorPass;

/**
 * Every sign-in of the Martis guard starts without a 2FA pass.
 *
 * The guard fires {@see Login} whatever the route: the password sign-in
 * (`/login` and `/api/auth/login`), a magic link, an invitation, SSO, the
 * remember-me cookie, impersonation. Forgetting the pass here, in one place,
 * means a sign-in path added later cannot forget to: the session keeps its
 * attributes across a login and a session regenerate, so a pass earned by
 * one user would otherwise be in the session of the next.
 *
 * Impersonation hands a pass over on purpose, after this listener has run
 * (see ImpersonationManager).
 */
final class ResetTwoFactorPass
{
    public function handle(Login $event): void
    {
        if ($event->guard !== GuardCatalog::martis()) {
            return;
        }

        // The store the request's session middleware started: the one the
        // challenge writes the pass to (and ImpersonationManager reads).
        $session = app()->bound('session.store') ? app('session.store') : null;

        if ($session instanceof Session) {
            TwoFactorPass::revoke($session);
        }
    }
}
