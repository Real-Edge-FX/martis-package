<?php

namespace Martis\Resources;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Martis\Actions\ActionEventRedactor;
use Martis\Auth\GuardCatalog;
use Martis\Enums\SortDirection;
use Martis\Fields\DateTime;
use Martis\Fields\Id;
use Martis\Fields\KeyValue;
use Martis\Fields\MorphTo;
use Martis\Fields\Status;
use Martis\Fields\Text;
use Martis\Fields\Textarea;
use Martis\Models\ActionEvent;
use Martis\Resource;
use Martis\ResourceRegistry;
use Martis\Support\TranslatedLine;

/**
 * Built-in resource for browsing the martis_action_events audit log.
 *
 * Registered automatically by MartisServiceProvider when
 * config("martis.action_events.resource") is true (default).
 *
 * Users can hide this resource from the sidebar by overriding
 * displayInNavigation() or setting the config key to false.
 *
 * This resource is read-only: create, update and delete are disabled.
 *
 * Access. The audit log is closed by default, as the log holds what
 * every user changed. A policy for the ActionEvent model that defines
 * `viewAny` / `view` decides, as for any resource (Nova's
 * `ActionResource` follows the ActionEvent policy too); an ability the
 * policy does not define, or no policy at all, falls back to the
 * `view-martis-action-events` gate, which denies until the host
 * defines it. Without access the resource answers 403, leaves the
 * navigation and the command palette, and a relationship panel that
 * lists it (the "Action Events" panel of an `Actionable` model, or a
 * `MorphMany` declared by hand) leaves the detail page, its route
 * answering 403, as every relationship panel whose related resource the
 * user may not `viewAny`.
 *
 * Redaction. `original` and `changes` show a value only when the
 * viewer may see that attribute on the record's own detail page
 * ({@see ActionEventRedactor}); other values read `******`.
 */
class ActionEventResource extends Resource
{
    /** The gate that opens the audit log when no policy decides. */
    public const GATE = 'view-martis-action-events';

    /** {@inheritdoc} */
    public function authorizedToViewAny(Request $request): bool
    {
        if ($this->policyDefinesAbility('viewAny')) {
            return parent::authorizedToViewAny($request);
        }

        return static::gateAllows($request);
    }

    /** {@inheritdoc} */
    public function authorizedToView(Request $request): bool
    {
        if ($this->policyDefinesAbility('view')) {
            return parent::authorizedToView($request);
        }

        return static::gateAllows($request);
    }

    /** Whether the `view-martis-action-events` gate allows the request's user. */
    protected static function gateAllows(Request $request): bool
    {
        $user = $request->user();

        if ($user === null) {
            return false;
        }

        return Gate::forUser($user)->allows(static::GATE);
    }

    /**
     * Eager-loads the user who ran each action (Nova's `$with = ['user']`),
     * so the Initiated By column costs one query per page. Skipped when the
     * Martis guard's user model does not exist: the column then shows the
     * stored id.
     *
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    public static function applyWith(Builder $query): Builder
    {
        $query = parent::applyWith($query);

        if (class_exists(GuardCatalog::martisUserModel())) {
            $query->with('user');
        }

        return $query;
    }

    /** {@inheritdoc} */
    public static function globallySearchable(): bool
    {
        return false;
    }

    /** {@inheritdoc} */
    public static function model(): string
    {
        return ActionEvent::class;
    }

    /** {@inheritdoc} */
    public static function label(): string
    {
        return TranslatedLine::get('martis::action_events.label');
    }

    /** {@inheritdoc} */
    public static function singularLabel(): string
    {
        return TranslatedLine::get('martis::action_events.singular_label');
    }

    /** {@inheritdoc} */
    public static function titleAttribute(): string
    {
        return 'name';
    }

    /** {@inheritdoc} */
    public static function defaultSort(): ?string
    {
        return 'created_at';
    }

    /** {@inheritdoc} */
    public static function defaultSortDirection(): SortDirection
    {
        return SortDirection::Desc;
    }

    /** {@inheritdoc} */
    public static function subtitle(): ?string
    {
        return TranslatedLine::get('martis::action_events.subtitle');
    }

    /** {@inheritdoc} */
    public function icon(): string
    {
        return 'clipboard-text';
    }

    /** {@inheritdoc} */
    public function group(): ?string
    {
        return null;
    }

    /** {@inheritdoc} */
    public function belongsToSystemSection(): bool
    {
        // Audit log lives in the System section alongside Cache admin
        // and (when scaffolded via `martis:roles`) the Roles, Permissions,
        // and Users resources. Closed until the host grants the
        // `view-martis-action-events` gate or an ActionEvent policy.
        return true;
    }

    // -------------------------------------------------------------------------
    // Read-only: disable create, update, delete
    // -------------------------------------------------------------------------

    /** {@inheritdoc} */
    public function authorizedToCreate(Request $request): bool
    {
        return false;
    }

    /** {@inheritdoc} */
    public function authorizedToUpdate(Request $request): bool
    {
        return false;
    }

    /** {@inheritdoc} */
    public function authorizedToDelete(Request $request): bool
    {
        return false;
    }

    // -------------------------------------------------------------------------
    // Fields
    // -------------------------------------------------------------------------

    /**
     * The fields of Nova 5's `ActionResource`, in its order and with its
     * labels: ID, Name, Initiated By, Target, Status, Original, Changes,
     * Exception, Happened At. The index shows ID to Status and Happened
     * At; Original / Changes only appear when the event holds a diff, as
     * Nova adds them only when they are set.
     */
    public function fields(Request $request): array
    {
        $waiting = static::statusLabel('waiting');
        $running = static::statusLabel('running');

        return [
            Id::make('id', TranslatedLine::get('martis::action_events.id')),

            Text::make('name', TranslatedLine::get('martis::action_events.name'))
                ->displayUsing(static fn (mixed $value): mixed => is_string($value) && $value !== '' ? TranslatedLine::get($value) : $value)
                ->sortable()
                ->searchable(),

            Text::make('user_id', TranslatedLine::get('martis::action_events.initiated_by'))
                ->displayUsing(static fn (mixed $value, Model $model): mixed => $model instanceof ActionEvent
                    ? static::initiatorName($model)
                    : $value)
                ->exceptOnForms(),

            MorphTo::make('target', TranslatedLine::get('martis::action_events.target'))
                ->resolveUsing(static fn (Model $model): ?array => $model instanceof ActionEvent
                    ? static::targetValue($model, request())
                    : null)
                ->exceptOnForms(),

            Status::make('status', TranslatedLine::get('martis::action_events.status'))
                ->displayUsing(static fn (mixed $value): mixed => is_string($value) && $value !== '' ? static::statusLabel($value) : $value)
                ->loadingWhen([$waiting, $running])
                ->failedWhen([static::statusLabel('failed'), static::statusLabel('denied')])
                ->sortable(),

            KeyValue::make('original', TranslatedLine::get('martis::action_events.original'))
                ->resolveUsing(static fn (mixed $value, Model $model, string $attribute, ?Request $request = null): mixed => $model instanceof ActionEvent
                    ? ActionEventRedactor::redact($model, $value, $request ?? request())
                    : $value)
                ->canSeeForModel(static fn (Request $request, Model $model): bool => static::holdsDiff($model->getAttribute('original')))
                ->exceptOnForms(),

            KeyValue::make('changes', TranslatedLine::get('martis::action_events.changes'))
                ->resolveUsing(static fn (mixed $value, Model $model, string $attribute, ?Request $request = null): mixed => $model instanceof ActionEvent
                    ? ActionEventRedactor::redact($model, $value, $request ?? request())
                    : $value)
                ->canSeeForModel(static fn (Request $request, Model $model): bool => static::holdsDiff($model->getAttribute('changes')))
                ->exceptOnForms(),

            Textarea::make('exception', TranslatedLine::get('martis::action_events.exception'))
                ->hideFromIndex()
                ->nullable(),

            DateTime::make('created_at', TranslatedLine::get('martis::action_events.happened_at'))
                ->sortable()
                ->exceptOnForms(),
        ];
    }

    /**
     * The status as Nova labels it: Waiting, Running, Finished, Failed.
     *
     * Martis records `queued` for an action waiting in the queue and
     * `completed` for one that ran (Nova's `waiting` / `finished`); the
     * audit listeners record `finished` and, for an authorization denial,
     * `denied`. A status Martis does not know reads as its capitalised
     * value, as Nova translates `ucfirst($status)`.
     */
    public static function statusLabel(string $status): string
    {
        $key = match (strtolower($status)) {
            'queued', 'waiting' => 'waiting',
            'running' => 'running',
            'completed', 'finished' => 'finished',
            'failed' => 'failed',
            'denied' => 'denied',
            default => null,
        };

        if ($key === null) {
            return TranslatedLine::get(ucfirst($status));
        }

        return TranslatedLine::get('martis::action_events.status_'.$key);
    }

    /**
     * The name of the user who ran the action: the Martis guard's user
     * model (`GuardCatalog::martisUserModel()`, the `user()` relation),
     * its `name`, else its `email`, else the stored id when the user is
     * gone (Nova prints "Nova User" there; the id says more in an audit).
     */
    public static function initiatorName(ActionEvent $event): mixed
    {
        $id = $event->getAttribute('user_id');

        if ($id === null || $id === '') {
            return null;
        }

        try {
            $user = $event->relationLoaded('user') ? $event->getRelation('user') : $event->user()->first();
        } catch (\Throwable) {
            // The configured user model does not exist or cannot be queried.
            $user = null;
        }

        if ($user instanceof Model) {
            foreach (['name', 'email'] as $attribute) {
                $value = $user->getAttribute($attribute);
                if (is_scalar($value) && (string) $value !== '') {
                    return (string) $value;
                }
            }
        }

        return $id;
    }

    /**
     * The target of the event, as the `MorphTo` display reads it.
     *
     * Nova's `MorphToActionTarget`: the target record's resource label
     * and title, linked to its detail page when the viewer may view it.
     * Martis shows the title only then: for a record the viewer may not
     * view (or a record gone, or a model no resource exposes) it shows
     * the label and the stored id, unlinked, so the log does not tell
     * more about the record than its detail page would.
     *
     * @return array{type: string, id: string, title: string, resourceType: string|null, resourceLabel?: string}|null
     */
    public static function targetValue(ActionEvent $event, Request $request): ?array
    {
        $type = $event->getAttribute('target_type');
        $id = $event->getAttribute('target_id');

        if (! is_string($type) || $type === '' || $id === null || $id === '') {
            return null;
        }

        $id = (string) $id;
        $modelClass = Relation::getMorphedModel($type) ?? $type;
        $resourceClass = static::resourceForModel($modelClass);
        $label = $resourceClass !== null ? $resourceClass::singularLabel() : class_basename($modelClass);
        $fallback = ['type' => $type, 'id' => $id, 'title' => $label.': '.$id, 'resourceType' => null];

        if ($resourceClass === null) {
            return $fallback;
        }

        try {
            $query = $modelClass::query();
            if ($resourceClass::softDeletes()) {
                $query->withTrashed();
            }
            $record = $query->find($id);
        } catch (\Throwable) {
            $record = null;
        }

        if (! $record instanceof Model) {
            return $fallback;
        }

        $resource = new $resourceClass($record);

        if (! $resource->authorizedToViewAny($request) || ! $resource->authorizedToView($request)) {
            return $fallback;
        }

        $title = $resource->title();

        return [
            'type' => $type,
            'id' => $id,
            'title' => $title !== '' ? $title : $id,
            'resourceType' => $resourceClass::uriKey(),
            'resourceLabel' => $label,
        ];
    }

    /**
     * The registered resource that exposes `$modelClass`, if any.
     *
     * @return class-string<resource>|null
     */
    protected static function resourceForModel(string $modelClass): ?string
    {
        $modelClass = ltrim($modelClass, '\\');

        foreach (app(ResourceRegistry::class)->list() as $resourceClass) {
            if (ltrim($resourceClass::model(), '\\') === $modelClass) {
                return $resourceClass;
            }
        }

        return null;
    }

    /** Whether a stored `original` / `changes` value holds a diff to show. */
    protected static function holdsDiff(mixed $value): bool
    {
        if (is_string($value)) {
            $value = json_decode($value, true);
        }

        return is_array($value) && $value !== [];
    }
}
