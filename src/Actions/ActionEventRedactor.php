<?php

namespace Martis\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo as EloquentBelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany as EloquentBelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphTo as EloquentMorphTo;
use Illuminate\Http\Request;
use Martis\Contracts\FieldContract;
use Martis\FieldContext;
use Martis\Fields\BelongsToMany as BelongsToManyField;
use Martis\Fields\Field;
use Martis\Fields\MorphToMany as MorphToManyField;
use Martis\Fields\Repeater;
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
 *    out of the viewer's global scopes (another tenant) masks every key. A
 *    record that no longer exists (hard-deleted) is judged on a model
 *    hydrated from the attributes the event stored: the `view` policy must
 *    allow it, and an attribute the policy needs that the action did not
 *    change is missing from it, so the values stay masked.
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
 * A key outside the model's `$visible` (when it declares one) is not stored
 * at all; a `$hidden` key stays in the payload with {@see self::MASK} as its
 * value, so the log still tells which attributes changed. Used by the built-in
 * `ActionEventResource`; a custom audit resource calls {@see self::redact()}
 * from its own `resolveUsing()`.
 */
final class ActionEventRedactor
{
    /** The value a masked key shows. */
    public const MASK = '******';

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
     * `$values` (an `original` / `changes` diff about to be stored) as Nova
     * stores one: when the model's class declares `$visible`, only those
     * keys are kept, then the `$hidden` values are masked
     * ({@see self::maskHiddenAttributes()}). Nova builds its diffs through
     * `Orchestra\Sidekick\Eloquent\model_state()`, whose
     * `attributesToArray()` on a fresh instance of the model keeps only the
     * class's `$visible` attributes; a runtime `makeVisible()` does not
     * count there either.
     *
     * @param  array<array-key, mixed>  $values
     * @param  Model|class-string<Model>  $model  The model (or pivot) whose attributes `$values` holds.
     * @return array<array-key, mixed>
     */
    public static function loggableAttributes(array $values, Model|string $model): array
    {
        $visible = self::visibleAttributes($model instanceof Model ? $model::class : $model);

        if ($visible !== []) {
            $values = array_intersect_key($values, array_flip($visible));
        }

        return self::maskHiddenAttributes($values, $model);
    }

    /**
     * The values of an action's fields as its event stores them.
     *
     * An action run used to log the raw `fields` input of the request: every
     * value the user typed, a `Password` field included, the values for
     * fields the user cannot see and keys that name no field. The event now
     * keeps the values the run resolved for the fields the user may see
     * (`canSee()`), in plain text for what a reader of the log may use, and
     * {@see self::MASK} for a secret:
     *
     *  - a `Password` field, and any field that declares itself `sensitive()`,
     *    stores the mask instead of a value (an empty value stays empty);
     *  - inside a `Repeater` row, the fields its repeatables mark sensitive are
     *    masked the same way;
     *  - a key that names no visible field (a field the user cannot see, a value
     *    a custom component posted under a name of its own) is left out. The
     *    action still receives it in `handle()`.
     *
     * A pivot action's event stores its fields the same way.
     *
     * @param  list<FieldContract>  $fields  The action's declared fields.
     * @param  array<string, mixed>  $values  The values the run resolved (`ActionFields::all()`).
     * @return array<string, mixed>
     */
    public static function loggableFields(array $fields, array $values, Request $request): array
    {
        $logged = [];

        foreach ($fields as $field) {
            $attribute = $field->attribute();

            if (! $field->isAuthorizedToSee($request) || ! array_key_exists($attribute, $values)) {
                continue;
            }

            $value = $values[$attribute];

            if ($field instanceof Field && $field->isSensitive()) {
                $logged[$attribute] = $value === null || $value === '' || $value === [] ? $value : self::MASK;

                continue;
            }

            if ($field instanceof Repeater && is_array($value)) {
                $value = self::maskRows($value, $field->sensitiveRowAttributes($request));
            }

            $logged[$attribute] = $value;
        }

        return $logged;
    }

    /**
     * `$value` with the value of each key named in `$sensitive` replaced by
     * the mask, at any depth.
     *
     * @param  array<array-key, mixed>  $value
     * @param  list<string>  $sensitive
     * @return array<array-key, mixed>
     */
    private static function maskRows(array $value, array $sensitive): array
    {
        if ($sensitive === []) {
            return $value;
        }

        foreach ($value as $key => $item) {
            if (in_array((string) $key, $sensitive, true) && $item !== null && $item !== '' && $item !== []) {
                $value[$key] = self::MASK;
            } elseif (is_array($item)) {
                $value[$key] = self::maskRows($item, $sensitive);
            }
        }

        return $value;
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

        // A record the event names that no longer exists (hard-deleted) is
        // judged like one that does: the policy runs on a model hydrated
        // from what the event stored, so the `view` ability cannot be
        // skipped by deleting the row. An event that names no record has no
        // record to judge, only its resource's `viewAny`.
        $hydrated = $record === null && self::namesRecord($event);
        $record ??= $hydrated ? self::hydrate($type, $event) : new $type;

        $isPivotEvent = is_string($event->model_type) && $event->model_type !== $type;
        $keys = [];
        $viewable = false;
        $pivot = ['declared' => false, 'visible' => false, 'hidden' => [], 'shown' => []];

        foreach ($resources as $resourceClass) {
            $resource = new $resourceClass($record);

            if (! $resource->authorizedToViewAny($request)) {
                continue;
            }

            if ($record->exists && ! self::mayView($resource, $request, $hydrated)) {
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
     * Whether the event names a record (a model class and a key).
     */
    private static function namesRecord(ActionEvent $event): bool
    {
        $id = $event->actionable_id;

        return $id !== null && $id !== '';
    }

    /**
     * A model of `$modelClass` rebuilt from what the event stored: its key,
     * the attributes of `original`, then those of `changes` (what the record
     * held last). It holds only the attributes the action changed (the log
     * stores the diff, minus the model's `$hidden` values), so a policy that
     * needs another one (an owner or tenant column the action did not touch)
     * cannot prove the viewer may view it, and the values stay masked.
     *
     * @param  class-string<Model>  $modelClass
     */
    private static function hydrate(string $modelClass, ActionEvent $event): Model
    {
        $attributes = [];
        foreach (['original', 'changes'] as $column) {
            $stored = $event->getAttribute($column);

            if (is_string($stored)) {
                $stored = json_decode($stored, true);
            }

            if (! is_array($stored)) {
                continue;
            }

            foreach ($stored as $key => $value) {
                // A masked value ({@see self::MASK}) is not the attribute's.
                if (is_string($key) && $value !== self::MASK) {
                    $attributes[$key] = $value;
                }
            }
        }

        $model = new $modelClass;
        $model->forceFill($attributes);
        $model->setAttribute($model->getKeyName(), $event->actionable_id);
        $model->exists = true;
        $model->syncOriginal();

        return $model;
    }

    /**
     * The resource's `view` ability on its record. A model hydrated from an
     * event is partial, so a policy written for a complete record can fail on
     * it (a relation of a missing foreign key): that denies, it never raises.
     */
    private static function mayView(Resource $resource, Request $request, bool $hydrated): bool
    {
        if (! $hydrated) {
            return $resource->authorizedToView($request);
        }

        try {
            return $resource->authorizedToView($request);
        } catch (\Throwable) {
            return false;
        }
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

    /**
     * The `$visible` attributes a fresh instance of a model class declares,
     * or none when the class is not a model.
     *
     * @return list<string>
     */
    private static function visibleAttributes(string $modelClass): array
    {
        if (! is_subclass_of($modelClass, Model::class)) {
            return [];
        }

        return array_values(array_map('strval', (new $modelClass)->getVisible()));
    }
}
