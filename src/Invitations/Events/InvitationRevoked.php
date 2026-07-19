<?php

declare(strict_types=1);

namespace Martis\Invitations\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Martis\Invitations\Invitation;

/**
 * Fired by `InvitationManager::revoke()` after a still-pending invitation
 * has been flipped to `revoked`. Subscribed by Martis's audit listener
 * (`Martis\Invitations\Listeners\RecordInvitation`) and available to
 * consumer service providers.
 */
class InvitationRevoked
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public Invitation $invitation,
    ) {}
}
