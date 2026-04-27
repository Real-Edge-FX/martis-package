<?php

declare(strict_types=1);

namespace Martis\Sso;

/**
 * Immutable value object describing an external identity returned by an
 * SSO provider after a successful authentication round-trip.
 *
 * Producers (provider drivers) populate the fields. Consumers (identity
 * resolver, role mapper, host-app hooks) read them. The `$raw` property
 * carries the full provider-specific payload for app-side hooks that
 * need claims beyond the standard set.
 */
final class SsoIdentity
{
    /**
     * @param  array<int, string>  $externalRoles  Names of groups / app
     *   roles the user belongs to in the external IdP. Used by the role
     *   mapper to resolve local Martis/Spatie roles.
     * @param  array<string, mixed>  $raw  Provider-specific full payload
     *   (claims, profile JSON). Hooks can read it to set custom user
     *   attributes that aren't covered by the standard `name`/`email`.
     * @param  string|null  $accessToken  OAuth access token. Provided so
     *   apps can hit downstream APIs in `afterLogin` hooks. Not stored
     *   by Martis itself.
     */
    public function __construct(
        public readonly string $provider,
        public readonly string $externalId,
        public readonly ?string $email,
        public readonly ?string $name,
        public readonly array $externalRoles = [],
        public readonly array $raw = [],
        public readonly ?string $accessToken = null,
    ) {}
}
