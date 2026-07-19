<?php

declare(strict_types=1);

namespace Martis\Invitations\Events;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Martis\Invitations\Invitation;

/**
 * Fired by `InvitationManager::accept()` once the enclosing DB transaction
 * has committed (via `DB::afterCommit()`), so the event never fires for a
 * claim that ultimately rolled back. Carries both the invitation and the
 * newly created user. Subscribed by Martis's audit listener
 * (`Martis\Invitations\Listeners\RecordInvitation`) and available to
 * consumer service providers.
 */
class InvitationAccepted
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public Invitation $invitation,
        public Authenticatable $user,
    ) {}
}
