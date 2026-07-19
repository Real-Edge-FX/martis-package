<?php

declare(strict_types=1);

namespace Martis\Invitations\Listeners;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Martis\Auth\Listeners\RecordRoleChange;
use Martis\Invitations\Events\InvitationAccepted;
use Martis\Invitations\Events\InvitationCreated;
use Martis\Invitations\Events\InvitationRevoked;
use Martis\Invitations\Invitation;
use Martis\Models\ActionEvent;

/**
 * Listener that records every invitation lifecycle transition (created /
 * accepted / revoked) into the `martis_action_events` audit log.
 *
 * Mirrors {@see RecordRoleChange}'s ActionEvent-write
 * shape. The invitation itself is the actionable/model; the target is the
 * invitee's email address (there is no persisted model to point at — the
 * invitee may not exist yet for `created` / `revoked`), so `target_type` is
 * the literal `'email'` sentinel and `target_id` carries the address itself
 * (the morph id columns are `string`-typed, so this is a safe fit).
 *
 * Defensive: skips silently when the audit table is missing (apps that
 * opted out of the `martis:install` migration set) or when the consumer
 * disables auditing via `martis.audit.invitations = false`. Either way the
 * domain change already happened before this listener runs, so a skip here
 * never blocks the feature.
 */
class RecordInvitation
{
    public function handleCreated(InvitationCreated $event): void
    {
        $this->record('invitation.created', $event->invitation);
    }

    public function handleAccepted(InvitationAccepted $event): void
    {
        $this->record('invitation.accepted', $event->invitation);
    }

    public function handleRevoked(InvitationRevoked $event): void
    {
        $this->record('invitation.revoked', $event->invitation);
    }

    protected function record(string $name, Invitation $invitation): void
    {
        if (! (bool) config('martis.audit.invitations', true)) {
            return;
        }

        if (! Schema::hasTable('martis_action_events')) {
            return;
        }

        // The inviting operator is the actor of record for the whole
        // lifecycle (created/accepted/revoked all describe what happened
        // to THEIR invitation); fall back to whoever is currently
        // authenticated (e.g. a console-issued invitation with no
        // `invited_by`, or an operator revoking someone else's invite).
        $userId = $invitation->invited_by ?? Auth::id();

        ActionEvent::create([
            'batch_id' => (string) Str::uuid(),
            'user_id' => $userId,
            'name' => $name,
            'actionable_type' => Invitation::class,
            'actionable_id' => $invitation->id,
            'target_type' => 'email',
            'target_id' => $invitation->email,
            'model_type' => Invitation::class,
            'model_id' => $invitation->id,
            'fields' => [
                'role' => $invitation->role,
                'invitation_id' => $invitation->id,
            ],
            'status' => 'finished',
            'exception' => '',
            'original' => [],
            'changes' => [],
        ]);
    }
}
