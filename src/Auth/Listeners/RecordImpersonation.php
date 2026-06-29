<?php

declare(strict_types=1);

namespace Martis\Auth\Listeners;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Martis\Impersonation\Events\ImpersonationStarted;
use Martis\Impersonation\Events\ImpersonationStopped;
use Martis\Models\ActionEvent;

/**
 * Listener that records every impersonation start / stop into the
 * `martis_action_events` audit log.
 *
 * Each row carries:
 *   - `name`            — `impersonation.started` / `impersonation.stopped`
 *   - `user_id`         — the operator (the user issuing the
 *                         impersonation; NOT the target)
 *   - `model_type` / `model_id`         — the target user
 *   - `target_type` / `target_id`       — the target user
 *   - `actionable_type` / `actionable_id` — the target user
 *   - `fields.target_label` / `target_id` — convenience copy for the
 *                                           audit-log row in the UI
 *
 * Defensive: skips silently when the audit table is missing
 * (`martis_action_events`) or when the consumer flips
 * `martis.audit.impersonation` to `false`.
 */
class RecordImpersonation
{
    public function handleStarted(ImpersonationStarted $event): void
    {
        $this->record('impersonation.started', $event->operator, $event->target);
    }

    public function handleStopped(ImpersonationStopped $event): void
    {
        $this->record('impersonation.stopped', $event->operator, $event->target);
    }

    protected function record(string $name, Authenticatable $operator, Authenticatable $target): void
    {
        if (! (bool) config('martis.audit.impersonation', true)) {
            return;
        }

        if (! Schema::hasTable('martis_action_events')) {
            return;
        }

        $targetType = $target::class;
        $targetId = $this->extractId($target);
        $targetLabel = $this->describeLabel($target);

        ActionEvent::create([
            'batch_id' => (string) Str::uuid(),
            'user_id' => $operator->getAuthIdentifier(),
            'name' => $name,
            'actionable_type' => $targetType,
            'actionable_id' => $targetId,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'model_type' => $targetType,
            'model_id' => $targetId,
            'fields' => [
                'target_id' => $targetId,
                'target_label' => $targetLabel,
            ],
            'status' => 'finished',
            'exception' => '',
            'original' => [],
            'changes' => [],
        ]);
    }

    protected function extractId(Authenticatable $user): int|string|null
    {
        $id = $user->getAuthIdentifier();

        return is_int($id) || is_string($id) ? $id : null;
    }

    protected function describeLabel(Authenticatable $user): ?string
    {
        foreach (['name', 'email'] as $attribute) {
            $value = $user->{$attribute} ?? null;
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }
}
