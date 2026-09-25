<?php

namespace Martis\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo as EloquentBelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany as EloquentBelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphTo as EloquentMorphTo;
use Illuminate\Http\Request;
use Martis\FieldContext;
use Martis\Fields\BelongsToMany as BelongsToManyField;
use Martis\Fields\Field;
use Martis\Fields\MorphToMany as MorphToManyField;
use Martis\Models\ActionEvent;
use Martis\Resource;
use Martis\ResourceRegistry;
use WeakMap;

/**
 * Masks the values of an action event's `original` / `changes` that the
 * viewer could not read on the record itself.
 *
 * An action event stores the raw attributes an action changed, whatever
 * the record's resource shows. Reading them back must not reveal more
 * than the record's own detail page does, so each key keeps its value
 * only when the viewer may see it there:
 *
 *  - the event names a model (`actionable_type`) that one or more
 *    registered resources expose: the key must belong to a field of the
 *    detail page (its attribute, or the foreign key / morph type of a
 *    `BelongsTo` / `MorphTo`) that the viewer may see (`canSee()`,
 *    `canSeeForModel()`) through a resource that lets the viewer
 *    `viewAny` and `view` the record. A record that still exists but is
 *    out of the viewer's global scopes (another tenant) masks every key.
 *  - a pivot action's event (its `model_type` is the pivot, not the
 *    record): the viewer must be able to view the parent record, and the
 *    pivot model's `$hidden` attributes are masked, as are the pivot
 *    fields' attributes the viewer may not see (`canSee()`) on the
 *    many-to-many field that lists the row. When the parent's detail page
 *    declares such a field but the viewer may see none of them, every
 *    value is masked.
 *  - the event names no model a resource exposes (a standalone action,
 *    a role change, a custom writer): the model's `$hidden` attributes
 *    are masked, the rest is kept.
 *
 * A masked key stays in the payload with {@see self::MASK} as its value,
 * so the log still tells which attributes changed. Used by the built-in
 * `ActionEventResource`; a custom audit resource calls {@see self::redact()}
 * from its own `resolveUsing()`.
 */
final class ActionEventRedactor
{
    /** The value a masked key shows. */
    public const MASK = '[hidden]';

    /**
     * Per-event visibility, computed once for the `original` and the
     * `changes` of the same event.
     *
     * @var WeakMap<ActionEvent, array{mode: string, keys: list<string>}>|null
     */
    private static ?WeakMap $memo = null;

    /**
     * Return `$values` with the keys the viewer may not see masked.
     */
    public static function redact(ActionEvent $event, mixed $values, Request $request): mixed
    {
        if (is_string($values)) {
            $decoded = json_decode($values, true);
            if (! is_array($decoded)) {
                return $values;
            }
            $values = $decoded;
        }

        if (! is_array($values) || $values === []) {
            return $values;
        }

        $visibility = self::visibility($event, $request);

        $masked = [];
        foreach ($values as $key => $value) {
            $allowed = match ($visibility['mode']) {
                'none' => false,
                'allow' => in_array((string) $key, $visibility['keys'], true),
                default => ! in_array((string) $key, $visibility['keys'], true),
            };

            $masked[$key] = $allowed ? $value : self::MASK;
        }

        return $masked;
    }

    /**
     * `$values` (an `original` / `changes` diff about to be stored) with the
     * value of each attribute the model hides (its `$hidden`) replaced by
     * {@see self::MASK}.
     *
     * Applied when an event is written, so a password hash or a token an
     * action changed never reaches the log, whoever reads it later. Nova
     * does the same: its action events store their diffs through
     * `Orchestra\Sidekick\Eloquent\model_state()`, which replaces each
     * `$hidden` attribute with a `SensitiveValue` serialised as `******`.
     * The key stays, so the log still tells that the attribute changed.
     *
     * @param  array<array-key, mixed>  $values
     * @param  Model|class-string<Model>  $model  The model (or pivot) whose attributes `$values` holds.
     * @return array<array-key, mixed>
     */
    public static function maskHiddenAttributes(array $values, Model|string $model): array
    {
        $hidden = $model instanceof Model
            ? array_map('strval', $model->getHidden())
            : self::hiddenAttributes($model);

        if ($hidden === []) {
            return $values;
        }

        foreach ($values as $key => $value) {
            if (in_array((string) $key, $hidden, true)) {
                $values[$key] = self::MASK;
            }
        }

        return $values;
    }

    /**
     * Forget the per-event visibility computed so far (tests, long-lived
     * workers that switch the authenticated user).
     */
    public static function flush(): void
    {
        self::$memo = null;
    }

    /**
     * How the viewer sees the event's keys: `allow` keeps only `keys`,
     * `deny` masks only `keys`, `none` masks every key.
     *
     * @return array{mode: string, keys: list<string>}
     */
    private static function visibility(ActionEvent $event, Request $request): array
    {
        self::$memo ??= new WeakMap;

        return self::$memo[$event] ??= self::computeVisibility($event, $request);
    }

    /**
     * @return array{mode: string, keys: list<string>}
     */
    private static function computeVisibility(ActionEvent $event, Request $request): array
    {
        $type = $event->actionable_type;

        if (! is_string($type) || ! is_subclass_of($type, Model::class)) {
            return ['mode' => 'deny', 'keys' => []];
        }

        $resources = self::resourcesFor($type);

        if ($resources === []) {
            return ['mode' => 'deny', 'keys' => self::hiddenAttributes($type)];
        }

        $record = self::record($type, $event->actionable_id);

        if ($record === false) {
            return ['mode' => 'none', 'keys' => []];
        }

        $record ??= new $type;

        $isPivotEvent = is_string($event->model_type) && $event->model_type !== $type;
        $keys = [];
        $viewable = false;
        $pivot = ['declared' => false, 'visible' => false, 'hidden' => [], 'shown' => []];

        foreach ($resources as $resourceClass) {
            $resource = new $resourceClass($record);

            if (! $resource->authorizedToViewAny($request)) {
                continue;
            }

            if ($record->exists && ! $resource->authorizedToView($request)) {
                continue;
            }

            $viewable = true;

            if ($isPivotEvent) {
                /** @var string $pivotType */
                $pivotType = $event->model_type;
                $pivot = self::pivotVisibility($resource, $record, $pivotType, $event->target_type, $request, $pivot);

                continue;
            }

            $fields = Field::filterForModel(
                Field::filterForContext($resource->resolveDetailFields($request), FieldContext::DETAIL, $request),
                $request,
                $record,
            );

            foreach ($fields as $field) {
                array_push($keys, ...self::revealedAttributes($field, $record));
            }
        }

        if (! $viewable) {
            return ['mode' => 'none', 'keys' => []];
        }

        if ($isPivotEvent) {
            // The viewer sees none of the relationship panels that list the
            // pivot row: every value is masked, as for a record they may not
            // view.
            if ($pivot['declared'] && ! $pivot['visible']) {
                return ['mode' => 'none', 'keys' => []];
            }

            /** @var string $pivotType */
            $pivotType = $event->model_type;

            return ['mode' => 'deny', 'keys' => array_values(array_unique(array_merge(
                self::hiddenAttributes($pivotType),
                array_diff($pivot['hidden'], $pivot['shown']),
            )))];
        }

        return ['mode' => 'allow', 'keys' => array_values(array_unique($keys))];
    }

    /**
     * What the viewer sees of a pivot row through `$resource`'s detail page:
     * whether a many-to-many field there lists the pivot model (`declared`),
     * whether the viewer may see one (`visible`), and the attributes of its
     * pivot fields the viewer may not see (`hidden`) or may (`shown`).
     *
     * A field lists the pivot row when its relationship on the record uses
     * the event's pivot model and relates the event's target model. Two
     * fields that match (two relationships through the same pivot model to
     * the same model) both count, a pivot attribute one of them shows
     * staying visible.
     *
     * @param  array{declared: bool, visible: bool, hidden: list<string>, shown: list<string>}  $carry
     * @return array{declared: bool, visible: bool, hidden: list<string>, shown: list<string>}
     */
    private static function pivotVisibility(Resource $resource, Model $record, string $pivotType, mixed $targetType, Request $request, array $carry): array
    {
        foreach (Field::flattenLayoutFields($resource->resolveDetailFields($request)) as $field) {
            if (! ($field instanceof BelongsToManyField || $field instanceof MorphToManyField)
                || ! $field->isVisibleForContext(FieldContext::DETAIL)
                || ! self::relationListsPivot($record, $field->getRelationship(), $pivotType, $targetType)) {
                continue;
            }

            $carry['declared'] = true;

            if (! $field->isAuthorizedToSee($request) || ! $field->isAuthorizedForModel($request, $record)) {
                continue;
            }

            $carry['visible'] = true;

            foreach ($field->getPivotFields() as $pivotField) {
                if (! $pivotField instanceof Field) {
                    continue;
                }

                if ($pivotField->isAuthorizedToSee($request)) {
                    $carry['shown'][] = $pivotField->attribute();
                } else {
                    $carry['hidden'][] = $pivotField->attribute();
                }
            }
        }

        return $carry;
    }

    /**
     * Whether the record's `$relationship` is a many-to-many relationship
     * through `$pivotType` to `$targetType`.
     */
    private static function relationListsPivot(Model $record, string $relationship, string $pivotType, mixed $targetType): bool
    {
        if ($relationship === '' || ! method_exists($record, $relationship)) {
            return false;
        }

        try {
            $relation = $record->{$relationship}();
        } catch (\Throwable) {
            return false;
        }

        if (! $relation instanceof EloquentBelongsToMany || ! is_a($pivotType, $relation->getPivotClass(), true)) {
            return false;
        }

        return ! is_string($targetType) || $targetType === '' || $relation->getRelated() instanceof $targetType;
    }

    /**
     * The registered resources that expose `$modelClass`.
     *
     * @param  class-string<Model>  $modelClass
     * @return list<class-string<resource>>
     */
    private static function resourcesFor(string $modelClass): array
    {
        return array_values(array_filter(
            app(ResourceRegistry::class)->list(),
            static fn (string $resourceClass): bool => $resourceClass::model() === $modelClass,
        ));
    }

    /**
     * The event's record: the model, null when it no longer exists, or
     * false when it exists outside the viewer's global scopes.
     *
     * @param  class-string<Model>  $modelClass
     */
    private static function record(string $modelClass, mixed $id): Model|false|null
    {
        if ($id === null || $id === '') {
            return null;
        }

        $record = $modelClass::query()->find($id);

        if ($record instanceof Model) {
            return $record;
        }

        return $modelClass::query()->withoutGlobalScopes()->whereKey($id)->exists() ? false : null;
    }

    /**
     * The model attributes a visible field shows: its attribute, plus the
     * foreign key (and morph type) of the relationship it names.
     *
     * @return list<string>
     */
    private static function revealedAttributes(mixed $field, Model $record): array
    {
        if (! $field instanceof Field) {
            return [];
        }

        $attribute = $field->attribute();
        $keys = [$attribute];

        if ($attribute !== '' && method_exists($record, $attribute)) {
            try {
                $relation = $record->{$attribute}();
            } catch (\Throwable) {
                $relation = null;
            }

            if ($relation instanceof EloquentMorphTo) {
                $keys[] = $relation->getForeignKeyName();
                $keys[] = $relation->getMorphType();
            } elseif ($relation instanceof EloquentBelongsTo) {
                $keys[] = $relation->getForeignKeyName();
            }
        }

        return $keys;
    }

    /**
     * The `$hidden` attributes of a model class, or none when the class
     * is not a model.
     *
     * @return list<string>
     */
    private static function hiddenAttributes(string $modelClass): array
    {
        if (! is_subclass_of($modelClass, Model::class)) {
            return [];
        }

        return array_values(array_map('strval', (new $modelClass)->getHidden()));
    }
}
