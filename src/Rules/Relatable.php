<?php

namespace Martis\Rules;

use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Martis\Contracts\FieldContract;
use Martis\Fields\BelongsTo;
use Martis\Fields\MorphTo;
use Martis\Fields\Tag;
use Martis\RelationshipQueryResolver;
use Martis\Resource;
use Martis\ResourceRegistry;

/**
 * The record a relationship field writes must be one its picker offers.
 *
 * Nova validates every `BelongsTo` / `MorphTo` save with its
 * `Laravel\Nova\Rules\Relatable` rule: the id must match the field's
 * associatable query (the one the picker lists, soft-deleted records left
 * out unless `{attribute}_trashed` is `true`), and the related resource's
 * `add{Model}` policy must allow it. Martis runs the same check on a
 * `BelongsTo`, a `MorphTo` and a `Tag`, with the query its own picker runs
 * (`RelationshipQueryResolver`: the target's `relatableQuery()`, the source
 * resource's `relatable{PluralModelName}()`, the field's
 * `relatableQueryUsing()` and `withoutTrashed()`), so a crafted request
 * cannot write a record the picker would never list.
 *
 * On top of the query:
 *
 * - the user must be allowed to list the related resource (`viewAny`), as
 *   the picker requires;
 * - a `BelongsTo` / `MorphTo` needs the related record's
 *   `add{SourceModel}` policy ability (Nova's `authorizedToAdd()`, allowed
 *   when the policy does not define it);
 * - a `Tag` needs the source resource's `attachAny{Model}` and
 *   `attach{Model}` abilities for every record it adds, as the attach
 *   endpoint (Nova's `Tag` field checks neither).
 *
 * A value the record already holds passes unchecked: an update that sends
 * the stored `BelongsTo` / `MorphTo` target back, or keeps a tag already
 * synced, is not rejected because the record fell out of the query since.
 * A soft-deleted related record passes only when the request sends
 * `{attribute}_trashed=true`, the related resource lets the user see trashed
 * records (`canViewTrashed()`) and the field is not `withoutTrashed()`.
 */
final class Relatable implements DataAwareRule, ValidationRule
{
    /**
     * The input the validator runs on.
     *
     * @var array<array-key, mixed>
     */
    private array $data = [];

    /**
     * @param  class-string<\Martis\Resource>|null  $sourceResourceClass  The resource whose form declares the field (the source of `relatable{PluralModelName}()`)
     * @param  Model|null  $record  The record the write fills (the new model on a create, the pivot row for pivot fields)
     */
    public function __construct(
        private readonly Request $request,
        private readonly BelongsTo|MorphTo|Tag $field,
        private readonly ?string $sourceResourceClass,
        private readonly ?Model $record = null,
    ) {}

    /**
     * The rule for `$field`, or `null` when the field is not a relationship
     * field the rule checks, or writes nothing from the request (readonly).
     *
     * @param  class-string<\Martis\Resource>|null  $sourceResourceClass
     */
    public static function forField(FieldContract $field, Request $request, ?string $sourceResourceClass, ?Model $record = null): ?self
    {
        if (! $field instanceof BelongsTo && ! $field instanceof MorphTo && ! $field instanceof Tag) {
            return null;
        }

        if ($field->isReadonly()) {
            return null;
        }

        return new self($request, $field, $sourceResourceClass, $record);
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $target = $this->target($value);

        if ($target === null) {
            return;
        }

        [$relatedResourceClass, $ids] = $target;

        if ($ids !== [] && ! $this->passes($relatedResourceClass, $ids, $attribute)) {
            $fail('martis::validation.relatable')->translate();
        }
    }

    /**
     * The related resource and the ids the value writes that the record does
     * not hold yet, or `null` when there is nothing to check.
     *
     * @return array{0: class-string<\Martis\Resource>, 1: list<int|string>}|null
     */
    private function target(mixed $value): ?array
    {
        $field = $this->field;

        if ($field instanceof BelongsTo) {
            $id = $field->submittedId($value);
            $relatedResourceClass = $this->resourceFor($field->getRelatedResource());

            if ($id === null || $relatedResourceClass === null) {
                return null;
            }

            $stored = $this->record?->getAttribute($field->attribute());

            if ($stored !== null && (string) $stored === (string) $id) {
                return null;
            }

            return [$relatedResourceClass, [$id]];
        }

        if ($field instanceof MorphTo) {
            $target = $field->submittedTarget($value);

            if ($target === null) {
                return null;
            }

            $storedType = $this->record?->getAttribute($field->getMorphTypeColumn());
            $storedId = $this->record?->getAttribute($field->getMorphIdColumn());

            // The stored type may be the model class or its morph-map alias.
            $targetModel = $target['resource']::newModel();
            $types = [$target['type'], $targetModel::class, $targetModel->getMorphClass()];

            if ($storedType !== null && $storedId !== null
                && in_array((string) $storedType, $types, true) && (string) $storedId === (string) $target['id']) {
                return null;
            }

            return [$target['resource'], [$target['id']]];
        }

        $relatedResourceClass = $this->resourceFor($field->getRelatedResource());

        if ($relatedResourceClass === null) {
            return null;
        }

        $ids = [];
        foreach ($field->extractIds($value) as $id) {
            $ids[(string) $id] = $id;
        }

        foreach ($this->syncedIds($field) as $synced) {
            unset($ids[$synced]);
        }

        return [$relatedResourceClass, array_values($ids)];
    }

    /**
     * Whether every id is one the picker offers and the user may write.
     *
     * @param  class-string<\Martis\Resource>  $relatedResourceClass
     * @param  non-empty-list<int|string>  $ids
     */
    private function passes(string $relatedResourceClass, array $ids, string $attribute): bool
    {
        if (! (new $relatedResourceClass)->authorizedToViewAny($this->request)) {
            return false;
        }

        $related = $relatedResourceClass::newModel();

        // An id an integer key cannot hold never reaches the database
        // (PostgreSQL rejects it where other drivers match nothing).
        if ($related->getKeyType() === 'int') {
            foreach ($ids as $id) {
                if (! is_int($id) && preg_match('/^-?\d+$/', $id) !== 1) {
                    return false;
                }
            }
        }

        $models = $this->relatableQuery($relatedResourceClass, $attribute)
            ->whereKey($ids)
            ->get()
            ->unique(fn (Model $model): string => (string) $model->getKey());

        if ($models->count() !== count($ids)) {
            return false;
        }

        foreach ($models as $model) {
            if (! $this->authorized($relatedResourceClass, $model)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The query the field's picker lists, with the trashed records only
     * when the request asks for them and may have them.
     *
     * @param  class-string<\Martis\Resource>  $relatedResourceClass
     * @return Builder<Model>
     */
    private function relatableQuery(string $relatedResourceClass, string $attribute): Builder
    {
        /** @var Builder<Model> $query */
        $query = $relatedResourceClass::newModel()->newQuery();

        if ($this->withTrashed($relatedResourceClass, $attribute)) {
            /** @phpstan-ignore-next-line guarded by softDeletes() */
            $query->withTrashed();
        }

        $query = $this->sourceResourceClass !== null
            ? RelationshipQueryResolver::resolve($this->sourceResourceClass, $relatedResourceClass, $this->request, $query, $this->field)
            : $relatedResourceClass::relatableQuery($this->request, $query);

        // The resolver's order does not change which rows match.
        $query->getQuery()->reorder();

        return $query;
    }

    /**
     * Nova's `{attribute}_trashed` opt-in, read next to the value in the
     * validated input.
     *
     * @param  class-string<\Martis\Resource>  $relatedResourceClass
     */
    private function withTrashed(string $relatedResourceClass, string $attribute): bool
    {
        if ($this->field instanceof BelongsTo && $this->field->isWithoutTrashed()) {
            return false;
        }

        if (! $relatedResourceClass::softDeletes() || ! $relatedResourceClass::canViewTrashed()) {
            return false;
        }

        return filter_var(Arr::get($this->data, $attribute.'_trashed'), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * The policy abilities the write needs for one related record.
     *
     * @param  class-string<\Martis\Resource>  $relatedResourceClass
     */
    private function authorized(string $relatedResourceClass, Model $related): bool
    {
        if ($this->sourceResourceClass === null) {
            return true;
        }

        if ($this->field instanceof Tag) {
            // The record the Tag syncs on is the source resource's model,
            // except among pivot fields, where it is the pivot row.
            $sourceModel = $this->sourceResourceClass::newModel();
            $record = $this->record instanceof $sourceModel ? $this->record : $sourceModel;
            $source = new ($this->sourceResourceClass)($record);

            return $source->authorizedToAttachAny($this->request, $related::class)
                && $source->authorizedToAttach($this->request, $related);
        }

        /** @var class-string<Model> $sourceModelClass */
        $sourceModelClass = $this->sourceResourceClass::model();

        return (new $relatedResourceClass($related))->authorizedToAdd($this->request, $sourceModelClass);
    }

    /**
     * The keys of the records a `Tag` relationship of the record holds.
     *
     * @return list<string>
     */
    private function syncedIds(Tag $field): array
    {
        $record = $this->record;

        if ($record === null || ! $record->exists) {
            return [];
        }

        // The method the sync writes through (see DeferredRelationSync).
        $relationship = $field->getRelationship();

        if (! method_exists($record, $relationship)) {
            $relationship = Str::camel($relationship);
        }

        if (! method_exists($record, $relationship)) {
            return [];
        }

        $relation = $record->{$relationship}();

        if (! $relation instanceof Relation) {
            return [];
        }

        $related = $relation->getRelated();

        return array_values($relation->pluck($related->qualifyColumn($related->getKeyName()))
            ->map(fn (mixed $key): string => (string) $key)
            ->all());
    }

    /**
     * @return class-string<\Martis\Resource>|null
     */
    private function resourceFor(?string $uriKey): ?string
    {
        if ($uriKey === null) {
            return null;
        }

        $registry = app(ResourceRegistry::class);

        return $registry->has($uriKey) ? $registry->get($uriKey) : null;
    }
}
