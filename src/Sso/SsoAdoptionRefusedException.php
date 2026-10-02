<?php

declare(strict_types=1);

namespace Martis\Sso;

use RuntimeException;

/**
 * Thrown by IdentityResolver when the IdP's address is held by a local row
 * the sign-in may not adopt: the row's email is not verified while the panel
 * lets anyone register an account. Adopting it would hand the registrant (who
 * chose the address and the password) the IdP user's roles and sessions.
 *
 * SsoController catches it, logs it and shows a translated refusal; a host
 * closure registered through `MartisSso::resolveUserUsing()` bypasses the
 * resolver and decides for itself.
 */
class SsoAdoptionRefusedException extends RuntimeException
{
    public function __construct(
        public readonly string $provider,
        public readonly string $email,
    ) {
        parent::__construct(
            "SSO sign-in via [{$provider}] refused: a local account already holds [{$email}] and this sign-in may not adopt or duplicate it (unverified email with self-registration open, or an address the matching strategy did not link).",
        );
    }
}
