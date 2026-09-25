<?php

namespace Martis\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo as EloquentBelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo as EloquentMorphTo;
use Illuminate\Http\Request;
use Martis\FieldContext;
use Martis\Fields\Field;
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
 *    pivot model's `$hidden` attributes are masked.
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
                continue;
            }

            $fields = Field::filterForModel(
                Field::filterForContext($resource->fieldsForDetail($request), FieldContext::DETAIL, $request),
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
            /** @var string $pivotType */
            $pivotType = $event->model_type;

            return ['mode' => 'deny', 'keys' => self::hiddenAttributes($pivotType)];
        }

        return ['mode' => 'allow', 'keys' => array_values(array_unique($keys))];
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
