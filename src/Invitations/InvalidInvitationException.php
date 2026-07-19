<?php

namespace Martis\Invitations;

use RuntimeException;

/**
 * Neutral, enumeration-safe failure for the invitation accept flow.
 *
 * Thrown by {@see InvitationManager::accept()} for every unacceptable
 * token state — unknown, expired, revoked, already-used, or an email
 * that already belongs to a registered user. The message is deliberately
 * uniform so an attacker cannot distinguish "no such token" from "token
 * already claimed" and enumerate valid invitations by probing the accept
 * endpoint.
 */
class InvalidInvitationException extends RuntimeException
{
    public function __construct(string $message = 'This invitation is no longer valid.')
    {
        parent::__construct($message);
    }
}
