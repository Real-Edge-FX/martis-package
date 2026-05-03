<?php

declare(strict_types=1);

namespace Martis\Auth\Listeners;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Martis\Models\ActionEvent;

/**
 * Listener that records every Spatie role / permission attach / detach
 * event into the `martis_action_events` audit log.
 *
 * Off the shelf the Spatie events fire whenever a `HasRoles` model
 * calls `assignRole`, `removeRole`, `syncRoles`, `givePermissionTo`,
 * `revokePermissionTo`, etc. The Martis listener captures the
 * acting user (from `Auth::user()` if present), the affected target
 * row, and the list of role / permission ids involved, and writes a
 * single `ActionEvent` row per dispatch.
 *
 * When the audit table is missing (apps that opted out of the v0.7
 * `martis:install` migration set), the listener short-circuits — the
 * domain change still happens, the audit row is just skipped. Same
 * for the case where the consumer disables auditing entirely via
 * `martis.audit.role_changes = false`.
 */
class RecordRoleChange
{
    /** @var string Cached audit table name; resolved once and reused. */
    protected static string $table = 'martis_action_events';

    /**
     * @param  string  $name  ActionEvent.name (`role.attached`, `permission.detached`, ...)
     * @param  Model  $model  The model the role/permission was applied to (typically the User).
     * @param  mixed  $rolesOrIds  Whatever Spatie passes — array, Collection, or single id/Role.
     */
    public function record(string $name, Model $model, mixed $rolesOrIds): void
    {
        if (! (bool) config('martis.audit.role_changes', true)) {
            return;
        }

        if (! Schema::hasTable(static::$table)) {
            return;
        }

        $ids = $this->normaliseIds($rolesOrIds);
        if ($ids === []) {
            return;
        }

        $authUser = Auth::user();

        ActionEvent::create([
            'batch_id' => (string) Str::uuid(),
            'user_id' => $authUser?->getAuthIdentifier(),
            'name' => $name,
            'actionable_type' => $model::class,
            'actionable_id' => $this->extractId($model),
            'target_type' => $model::class,
            'target_id' => $this->extractId($model),
            'model_type' => $model::class,
            'model_id' => $this->extractId($model),
            'fields' => ['ids' => $ids],
            'status' => 'finished',
            'exception' => '',
            'original' => [],
            'changes' => ['ids' => $ids],
        ]);
    }

    public function handleRoleAttached(object $event): void
    {
        $this->dispatchIfShape($event, 'role.attached');
    }

    public function handleRoleDetached(object $event): void
    {
        $this->dispatchIfShape($event, 'role.detached');
        $this->maybeRevokeSessions($event);
    }

    public function handlePermissionAttached(object $event): void
    {
        $this->dispatchIfShape($event, 'permission.attached');
    }

    public function handlePermissionDetached(object $event): void
    {
        $this->dispatchIfShape($event, 'permission.detached');
        $this->maybeRevokeSessions($event);
    }

    /**
     * v1.8.8 — when `martis.authz.revoke_sessions_on_demote` is true,
     * detach events trigger a session sweep on the affected user. Any
     * active session for that user (other than the operator's current
     * one) is dropped immediately, so a demotion takes effect on every
     * device without waiting for the cookie to expire.
     *
     * Skips silently when the host app does not use the database
     * session driver (BrowserSessionsService surfaces a
     * `supported: false` envelope in that case; nothing to revoke).
     */
    protected function maybeRevokeSessions(object $event): void
    {
        if (! (bool) config('martis.authz.revoke_sessions_on_demote', false)) {
            return;
        }

        $model = property_exists($event, 'model') ? $event->model : null;
        if (! $model instanceof Model) {
            return;
        }

        $userId = $model->getKey();
        if (! is_int($userId) && ! is_string($userId)) {
            return;
        }

        $driver = (string) config('session.driver', 'file');
        if ($driver !== 'database') {
            return;
        }

        $table = (string) config('session.table', 'sessions');
        if (! Schema::hasTable($table)) {
            return;
        }

        // Drop EVERY session row for the demoted user. The current
        // session belongs to the OPERATOR (admin), not the demoted
        // user, so a wholesale delete is safe — the operator stays
        // signed in on their own session row.
        DB::table($table)
            ->where('user_id', $userId)
            ->delete();
    }

    protected function dispatchIfShape(object $event, string $name): void
    {
        // Spatie event objects expose `model` + `rolesOrIds` /
        // `permissionsOrIds`. Read defensively so a future Spatie
        // refactor that renames the property does not crash the
        // user's attach call — the audit row just gets skipped.
        $model = property_exists($event, 'model') ? $event->model : null;
        if (! $model instanceof Model) {
            return;
        }

        $payload = match (true) {
            property_exists($event, 'rolesOrIds') => $event->rolesOrIds,
            property_exists($event, 'permissionsOrIds') => $event->permissionsOrIds,
            default => null,
        };

        if ($payload === null) {
            return;
        }

        $this->record($name, $model, $payload);
    }

    /**
     * @return list<int|string>
     */
    protected function normaliseIds(mixed $rolesOrIds): array
    {
        if ($rolesOrIds instanceof Model) {
            $id = $this->extractId($rolesOrIds);

            return $id !== null ? [$id] : [];
        }

        if ($rolesOrIds instanceof Collection) {
            $rolesOrIds = $rolesOrIds->all();
        }

        if (! is_array($rolesOrIds)) {
            return [$rolesOrIds];
        }

        $ids = [];
        foreach ($rolesOrIds as $entry) {
            if ($entry instanceof Model) {
                $id = $this->extractId($entry);
                if ($id !== null) {
                    $ids[] = $id;
                }

                continue;
            }
            if (is_int($entry) || is_string($entry)) {
                $ids[] = $entry;
            }
        }

        return $ids;
    }

    protected function extractId(Model $model): int|string|null
    {
        $id = $model->getKey();

        return is_int($id) || is_string($id) ? $id : null;
    }
}
