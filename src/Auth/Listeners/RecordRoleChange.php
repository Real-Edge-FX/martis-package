<?php

declare(strict_types=1);

namespace Martis\Auth\Listeners;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Martis\Auth\GuardCatalog;
use Martis\Models\ActionEvent;
use Throwable;

/**
 * Listener that records every Spatie role / permission attach / detach
 * event into the `martis_action_events` audit log.
 *
 * Off the shelf the Spatie events fire whenever a `HasRoles` model
 * calls `assignRole`, `removeRole`, `syncRoles`, `givePermissionTo`,
 * `revokePermissionTo`, etc. The Martis listener captures the
 * acting user (the Martis guard's user, in a panel request: the audit
 * log's `user()` resolves that guard's model), the affected target
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

        // The Martis guard's user, and only while that guard is the request's
        // guard (a panel request: MartisAuthenticate calls shouldUse()).
        // ActionEvent::user() resolves that guard's model, so a change made
        // elsewhere (a site request, even from a browser that also holds a
        // panel session, a job, a command) records no actor rather than
        // another account's id.
        $authUser = GuardCatalog::panelUser();

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
     * detach events trigger a session sweep on the affected users. Any
     * active session of those users (other than the current request's
     * session) is dropped immediately, so a demotion takes effect on every
     * device without waiting for the cookie to expire.
     *
     * Who is affected depends on the model the event names (v2.4.0):
     *
     *   - a user of the Martis guard (a role detached from them, or a
     *     permission revoked from them directly): that user;
     *   - a role (Spatie fires PermissionDetached with the Role as `model`
     *     when a permission is revoked from a role): the users who hold it,
     *     which `Role::users()` gives. Before v2.4.0 the sweep deleted the
     *     sessions of the user whose id equalled the ROLE's id, signing out
     *     an unrelated account and leaving the holders of the role, who had
     *     just lost the permission, signed in;
     *   - any other model, or a role whose users cannot be resolved (their
     *     model is not the Martis guard's): skipped, with a warning.
     *
     * Skips silently when the host app does not use the database
     * session driver (BrowserSessionsService surfaces a
     * `supported: false` envelope in that case; nothing to revoke).
     * Skips with a warning when the app's session guards sign in users of
     * more than one table: `sessions.user_id` holds the id of whichever
     * guard wrote the row, so a delete by the demoted user's id could sign
     * out another person who has the same id in the other table.
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

        $modelId = $model->getKey();
        if (! is_int($modelId) && ! is_string($modelId)) {
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

        if (GuardCatalog::sessionUserIdsAreAmbiguous()) {
            Log::warning('Martis: revoke_sessions_on_demote skipped. The session guards of config/auth.php sign in users of more than one table and sessions.user_id stores no table, so the sessions of the demoted user cannot be told apart from those of another user with the same id.', [
                'model' => $model::class,
                'id' => $modelId,
                'tables' => GuardCatalog::sessionUserTables(),
            ]);

            return;
        }

        $userIds = $this->demotedUserIds($model);
        if ($userIds === null) {
            Log::warning('Martis: revoke_sessions_on_demote skipped. The model the event names is neither a user of the Martis guard nor a role whose users are the Martis guard\'s, so the sessions of the users who lost the permission cannot be found.', [
                'model' => $model::class,
                'id' => $modelId,
            ]);

            return;
        }

        // Drop every session row of the demoted users, except the current
        // request's: the operator's own session stays, also when they hold
        // the role they just changed (their next request is judged on the
        // permissions they have left, as every request is).
        $currentSessionId = app()->bound('session.store') ? app('session.store')->getId() : null;

        foreach (array_chunk($userIds, 500) as $chunk) {
            DB::table($table)
                ->whereIn('user_id', $chunk)
                ->when(is_string($currentSessionId) && $currentSessionId !== '', fn ($query) => $query->where('id', '!=', $currentSessionId))
                ->delete();
        }
    }

    /**
     * The ids of the Martis guard's users the event's model stands for, or
     * null when they cannot be told. A user of the Martis guard stands for
     * itself; a role (any model with a `users()` relation, as Spatie's Role
     * has) stands for the users who hold it, provided those are the Martis
     * guard's users.
     *
     * @return list<int|string>|null
     */
    protected function demotedUserIds(Model $model): ?array
    {
        if ($this->isMartisUser($model)) {
            $id = $model->getKey();

            return is_int($id) || is_string($id) ? [$id] : null;
        }

        if (! method_exists($model, 'users')) {
            return null;
        }

        try {
            $relation = $model->users();
            if (! $relation instanceof Relation || ! $this->isMartisUser($relation->getRelated())) {
                return null;
            }

            $ids = [];
            foreach ($relation->pluck($relation->getRelated()->getQualifiedKeyName()) as $id) {
                if (is_int($id) || is_string($id)) {
                    $ids[] = $id;
                }
            }

            return $ids;
        } catch (Throwable) {
            // A role of a guard with no model, a missing pivot table: the
            // users cannot be resolved.
            return null;
        }
    }

    /**
     * Whether a model is a user of the Martis guard: an instance of the
     * model of the guard's provider, or of another class on the same
     * connection and table (an app's `Staff` beside its `User`), whose ids
     * name the same people.
     */
    protected function isMartisUser(Model $model): bool
    {
        $userModel = GuardCatalog::martisUserModel();

        if ($model instanceof $userModel) {
            return true;
        }

        if (! is_subclass_of($userModel, Model::class)) {
            return false;
        }

        $user = new $userModel;

        return $user->getTable() === $model->getTable()
            && $user->getConnection()->getName() === $model->getConnection()->getName();
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
            return (is_int($rolesOrIds) || is_string($rolesOrIds)) ? [$rolesOrIds] : [];
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
