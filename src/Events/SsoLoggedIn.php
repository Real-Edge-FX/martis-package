<?php

declare(strict_types=1);

namespace Martis\Events;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Events\Dispatchable;
use Martis\Sso\SsoIdentity;

/**
 * Dispatched once per successful SSO sign-in, after the user is logged in
 * (`Auth::login()`) and the SSO session marker is set. The `afterLogin` hooks
 * of `MartisSso` run earlier, before the sign-in; this event is the place for
 * a listener that needs the authenticated session.
 *
 * Usage:
 *   Event::listen(SsoLoggedIn::class, function (SsoLoggedIn $event) {
 *       AuditLog::record('sso.login', $event->user, ['provider' => $event->provider]);
 *   });
 */
class SsoLoggedIn
{
    use Dispatchable;

    public function __construct(
        /** The local user who signed in. */
        public readonly Authenticatable $user,

        /** The identity the provider returned. */
        public readonly SsoIdentity $identity,

        /** The provider name of the callback route. */
        public readonly string $provider,
    ) {}
}
