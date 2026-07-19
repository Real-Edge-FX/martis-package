<?php

declare(strict_types=1);

namespace Martis\Invitations\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Martis\Invitations\Invitation;

/**
 * Fired by `InvitationManager::invite()` after the invitation row has been
 * persisted. Subscribed by Martis's audit listener
 * (`Martis\Invitations\Listeners\RecordInvitation`) and available to
 * consumer service providers that want to add their own observers (send a
 * notification, sync an external CRM, etc).
 */
class InvitationCreated
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public Invitation $invitation,
    ) {}
}
