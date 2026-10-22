<?php

declare(strict_types=1);

namespace Martis\Auth\Listeners;

use Illuminate\Auth\Events\Login;
use Illuminate\Auth\SessionGuard;
use Illuminate\Contracts\Session\Session;
use Martis\Auth\GuardCatalog;
use Martis\Auth\RememberedTwoFactor;
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
 *
 * A sign-in through the remember-me cookie keeps the pass when the browser
 * also carries the marker a passed challenge left ({@see RememberedTwoFactor}):
 * both factors were proven on this device, so an expired session does not
 * ask for the code again, as in Nova.
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

        if (! $session instanceof Session) {
            return;
        }

        TwoFactorPass::revoke($session);

        $guard = auth()->guard($event->guard);
        if ($guard instanceof SessionGuard && $guard->viaRemember() && RememberedTwoFactor::trusts(request(), $event->user)) {
            TwoFactorPass::grant($session, $event->user);
        }
    }
}
