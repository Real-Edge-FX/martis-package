<?php

declare(strict_types=1);

namespace Martis\Sso\Contracts;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Martis\Sso\SsoIdentity;

/**
 * Contract every SSO provider implements. Two methods:
 *
 *  • `redirect()` — kicks the browser off to the IdP's authorization
 *    endpoint. Returns a Laravel redirect response.
 *
 *  • `resolveIdentity()` — handles the IdP callback, exchanges the
 *    authorization code for an access token, fetches the profile +
 *    roles/groups, and returns a populated `SsoIdentity`.
 */
interface SsoProviderContract
{
    /** Lowercase identifier used in routes and config keys (e.g. "azure"). */
    public function name(): string;

    /** Begin the auth flow. Typically a Socialite redirect. */
    public function redirect(Request $request): RedirectResponse;

    /** Complete the auth flow and return the resolved identity. */
    public function resolveIdentity(Request $request): SsoIdentity;
}
