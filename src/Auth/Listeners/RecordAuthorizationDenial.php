<?php

declare(strict_types=1);

namespace Martis\Auth\Listeners;

use Illuminate\Auth\Access\Events\GateEvaluated;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Martis\Models\ActionEvent;

/**
 * Listener that writes a `martis_action_events` row every time
 * Laravel's Gate denies an authorization check for an authenticated
 * user. Provides a forensic trail of "user X tried Y but was denied",
 * which is invaluable for compliance / regulated industries.
 *
 * Off by default — the volume can be high in busy apps. Flip
 * `MARTIS_AUDIT_AUTHZ_DENIALS=true` to enable.
 *
 * Defensive shape:
 *   - skips silently when `martis.audit.authz_denials` is false;
 *   - skips silently when no user authenticated the call (system / guest
 *     / pre-auth gate evaluations are not actionable);
 *   - skips silently when `martis_action_events` does not exist;
 *   - records `name = authz.denied`, `user_id = operator id`,
 *     `fields.ability`, optional `fields.model_id` / `fields.model_class`.
 *
 * Single de-dup window: a same ability + same model is only logged
 * once per request lifecycle, so a single page that runs five
 * `viewAny` checks for the same resource produces one row.
 */
class RecordAuthorizationDenial
{
    /** @var array<string, true> Per-request dedup keys. */
    protected array $seen = [];

    public function handle(GateEvaluated $event): void
    {
        if (! (bool) config('martis.audit.authz_denials', false)) {
            return;
        }

        if ($event->result !== false) {
            return;
        }

        $user = $event->user;
        if ($user === null) {
            return;
        }

        // Skip the noisy `viewAny` cascade Laravel runs for sidebar /
        // navigation. The denial is already implied when `view` is also
        // denied — recording both produces double the volume for zero
        // signal. Keep `viewAny` only when the consumer explicitly asks.
        if ($event->ability === 'viewAny' && (bool) config('martis.audit.authz_denials_include_viewany', false) === false) {
            return;
        }

        $arguments = $event->arguments ?? [];
        $modelClass = null;
        $modelId = null;
        foreach ($arguments as $arg) {
            if ($arg instanceof Model) {
                $modelClass = $arg::class;
                $id = $arg->getKey();
                $modelId = is_int($id) || is_string($id) ? $id : null;
                break;
            }
            if (is_string($arg) && $modelClass === null) {
                // Ability cascade: `Gate::denies('view', Post::class)`
                // passes the FQCN as a string instead of an instance.
                $modelClass = $arg;
            }
        }

        $dedupKey = sprintf(
            '%s|%s|%s|%s',
            (string) $user->getAuthIdentifier(),
            $event->ability,
            $modelClass ?? '',
            (string) ($modelId ?? ''),
        );

        if (isset($this->seen[$dedupKey])) {
            return;
        }
        $this->seen[$dedupKey] = true;

        if (! Schema::hasTable('martis_action_events')) {
            return;
        }

        ActionEvent::create([
            'batch_id' => (string) Str::uuid(),
            'user_id' => $user->getAuthIdentifier(),
            'name' => 'authz.denied',
            'actionable_type' => $modelClass,
            'actionable_id' => $modelId,
            'target_type' => $modelClass,
            'target_id' => $modelId,
            'model_type' => $modelClass,
            'model_id' => $modelId,
            'fields' => [
                'ability' => $event->ability,
                'model_class' => $modelClass,
                'model_id' => $modelId,
            ],
            'status' => 'denied',
            'exception' => '',
            'original' => [],
            'changes' => [],
        ]);
    }
}
