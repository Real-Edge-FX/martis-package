<?php

declare(strict_types=1);

namespace Martis\Auth\Listeners;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
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
    }

    public function handlePermissionAttached(object $event): void
    {
        $this->dispatchIfShape($event, 'permission.attached');
    }

    public function handlePermissionDetached(object $event): void
    {
        $this->dispatchIfShape($event, 'permission.detached');
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
     * @param  mixed  $rolesOrIds
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
