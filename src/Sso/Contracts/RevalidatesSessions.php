<?php

declare(strict_types=1);

namespace Martis\Sso\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Optional contract of an SSO provider class (v2.21.0), next to
 * {@see SsoProviderContract}: ask the identity provider whether the person
 * an SSO session belongs to is still allowed in.
 *
 * Martis calls it from the authenticated route stack, at most once per
 * `martis.auth.sso.providers.{name}.revalidate_every_minutes` per session,
 * with the signed-in user and the external id the IdP issued at sign-in (null
 * for a session opened before the id was recorded).
 *
 *  - `true`: the session goes on.
 *  - `false`: the session ends as an expired one does (sign-out without the
 *    federated logout, then the way back through the provider).
 *  - a thrown exception: reported, and the session is kept. The check runs
 *    again no sooner than a minute later (kept in the session) instead of
 *    after a whole interval, so an IdP outage never signs every user out; the
 *    hard bound of a session is `max_session_minutes`.
 *
 * Keep it cheap: it runs inside a request. Give the HTTP client a short
 * timeout (a few seconds): while the IdP is down every session waits that long
 * once a minute.
 */
interface RevalidatesSessions
{
    public function stillValid(Authenticatable $user, ?string $externalId): bool;
}
