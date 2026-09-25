<?php

namespace Martis\Rules;

use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Martis\Contracts\FieldContract;
use Martis\Fields\BelongsTo;
use Martis\Fields\Field;
use Martis\Fields\HasOne;
use Martis\Fields\HasOneOfMany;
use Martis\Fields\HasOneThrough;
use Martis\Fields\MorphOne;
use Martis\Fields\MorphOneOfMany;
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
 * out unless `{attribute}_trashed` is `true`), the relationship must not be
 * "full", and the related resource's `add{Model}` policy must allow it.
 * Martis runs the same check on a `BelongsTo`, a `MorphTo` and a `Tag`, with
 * the query its own picker runs (`RelationshipQueryResolver`: the target's
 * `relatableQuery()`, the source resource's `relatable{PluralModelName}()`,
 * the field's `relatableQueryUsing()` and `withoutTrashed()`), so a crafted
 * request cannot write a record the picker would never list.
 *
 * As in Nova the value the request sends is checked on every write, an
 * update that sends the stored value back included: a record whose target
 * left the query since answers 422 until the target changes.
 *
 * On top of the query:
 *
 * - the user must be allowed to list the related resource (`viewAny`), as
 *   the picker requires;
 * - a `BelongsTo` / `MorphTo` needs the related record's `add{SourceModel}`
 *   policy ability (Nova's `authorizedToAdd()`, allowed when the policy does
 *   not define it), and fails when the related record's inverse `HasOne` /
 *   `MorphOne` (not one-of-many, not Through) is already filled by another
 *   record (Nova's `relationshipIsFull()`);
 * - a `Tag` needs the source resource's `attachAny{Model}` and
 *   `attach{Model}` abilities for every record it adds and `detach{Model}`
 *   for every record it removes, as the attach and detach endpoints (Nova's
 *   `Tag` field checks none).
 *
 * A soft-deleted related record passes only when the request opts in, the
 * related resource lets the user see trashed records (`canViewTrashed()`)
 * and the field is not `withoutTrashed()`. The opt-in is Nova's
 * `{attribute}_trashed=true`, or a value map with `trashed: true`, which the
 * edit form sends back for a trashed target (Nova's form turns on its "With
 * Trashed" checkbox for one).
 *
 * In a `Repeater` row (`$inRow`) the value is stored in the row, not as a
 * relationship of the record: the query, `viewAny` and `add{Model}` apply,
 * the relationship checks (full, attach, detach) do not.
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
     * A `Tag` is checked even when its value is null: a null value syncs no
     * record, so it removes every one and needs their `detach{Model}`.
     */
    public bool $implicit = false;

    /**
     * @param  class-string<\Martis\Resource>|null  $sourceResourceClass  The resource whose form declares the field (the source of `relatable{PluralModelName}()`)
     * @param  Model|null  $record  The record the write fills (the new model on a create, the pivot row for pivot fields)
     * @param  bool  $inRow  The field is in a Repeater row
     */
    public function __construct(
        private readonly Request $request,
        private readonly BelongsTo|MorphTo|Tag $field,
        private readonly ?string $sourceResourceClass,
        private readonly ?Model $record = null,
        private readonly bool $inRow = false,
    ) {
        $this->implicit = $field instanceof Tag;
    }

    /**
     * The rule for `$field`, or `null` when the field is not a relationship
     * field the rule checks, or writes nothing from the request (readonly).
     *
     * @param  class-string<\Martis\Resource>|null  $sourceResourceClass
     */
    public static function forField(FieldContract $field, Request $request, ?string $sourceResourceClass, ?Model $record = null, bool $inRow = false): ?self
    {
        if (! $field instanceof BelongsTo && ! $field instanceof MorphTo && ! $field instanceof Tag) {
            return null;
        }

        if ($field->isReadonly()) {
            return null;
        }

        return new self($request, $field, $sourceResourceClass, $record, $inRow);
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
        $failure = $this->failure($attribute, $value);

        if ($failure !== null) {
            $fail($failure)->translate();
        }
    }

    /**
     * The translation key of the failure, or `null` when the value passes.
     */
    private function failure(string $attribute, mixed $value): ?string
    {
        $field = $this->field;

        if ($field instanceof BelongsTo) {
            $id = $field->submittedId($value);
            $relatedResourceClass = $this->resourceFor($field->getRelatedResource());

            if ($id === null || $relatedResourceClass === null) {
                return null;
            }

            return $this->checkTargets($relatedResourceClass, [$id], $attribute, $value);
        }

        if ($field instanceof MorphTo) {
            $target = $field->submittedTarget($value);

            return $target === null ? null : $this->checkTargets($target['resource'], [$target['id']], $attribute, $value);
        }

        $relatedResourceClass = $this->resourceFor($field->getRelatedResource());

        if ($relatedResourceClass === null) {
            return null;
        }

        $ids = [];
        foreach ($field->extractIds($value) as $id) {
            $ids[(string) $id] = $id;
        }

        $synced = $this->syncedModels($field);

        if ($ids !== []) {
            $failure = $this->checkTargets($relatedResourceClass, array_values($ids), $attribute, $value, array_diff_key($ids, $synced));

            if ($failure !== null) {
                return $failure;
            }
        }

        // The records the sync removes need detach{Model}, as the detach
        // endpoint requires.
        $source = $this->sourceResource();

        foreach (array_diff_key($synced, $ids) as $removed) {
            if ($source !== null && ! $source->authorizedToDetach($this->request, $removed)) {
                return 'martis::validation.detachable';
            }
        }

        return null;
    }

    /**
     * Check the ids the value names: the picker's query, `viewAny`, the
     * policies and, for a `BelongsTo` / `MorphTo`, a full inverse
     * relationship. `$attaching` holds the ids a `Tag` adds (keyed by
     * string key).
     *
     * @param  class-string<\Martis\Resource>  $relatedResourceClass
     * @param  non-empty-list<int|string>  $ids
     * @param  array<string, int|string>  $attaching
     */
    private function checkTargets(string $relatedResourceClass, array $ids, string $attribute, mixed $value, array $attaching = []): ?string
    {
        $failed = 'martis::validation.relatable';

        if (! (new $relatedResourceClass)->authorizedToViewAny($this->request)) {
            return $failed;
        }

        // An id an integer key cannot hold never reaches the database
        // (PostgreSQL rejects it where other drivers match nothing).
        if ($relatedResourceClass::newModel()->getKeyType() === 'int') {
            foreach ($ids as $id) {
                if (! is_int($id) && preg_match('/^-?\d+$/', $id) !== 1) {
                    return $failed;
                }
            }
        }

        /** @var Collection<int, Model> $models */
        $models = $this->relatableQuery($relatedResourceClass, $attribute, $value)
            ->whereKey($ids)
            ->get()
            ->unique(fn (Model $model): string => (string) $model->getKey());

        if ($models->count() !== count($ids)) {
            return $failed;
        }

        foreach ($models as $model) {
            if ($this->relationshipIsFull($relatedResourceClass, $model)) {
                return $failed;
            }

            if (! $this->authorized($relatedResourceClass, $model, $attaching)) {
                return $failed;
            }
        }

        return null;
    }

    /**
     * The query the field's picker lists, with the trashed records only
     * when the request asks for them and may have them.
     *
     * @param  class-string<\Martis\Resource>  $relatedResourceClass
     * @return Builder<Model>
     */
    private function relatableQuery(string $relatedResourceClass, string $attribute, mixed $value): Builder
    {
        /** @var Builder<Model> $query */
        $query = $relatedResourceClass::newModel()->newQuery();

        if ($this->withTrashed($relatedResourceClass, $attribute, $value)) {
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
     * The trashed opt-in: Nova's `{attribute}_trashed`, read next to the
     * value in the validated input, or `trashed: true` in a `BelongsTo` /
     * `MorphTo` value map (what the form sends back for a trashed target).
     *
     * @param  class-string<\Martis\Resource>  $relatedResourceClass
     */
    private function withTrashed(string $relatedResourceClass, string $attribute, mixed $value): bool
    {
        if ($this->field instanceof BelongsTo && $this->field->isWithoutTrashed()) {
            return false;
        }

        if (! $relatedResourceClass::softDeletes() || ! $relatedResourceClass::canViewTrashed()) {
            return false;
        }

        if (! $this->field instanceof Tag && is_array($value) && ($value['trashed'] ?? null) === true) {
            return true;
        }

        return filter_var(Arr::get($this->data, $attribute.'_trashed'), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Nova's `relationshipIsFull()`: the related record's inverse `HasOne` /
     * `MorphOne` (declared on the related resource, pointing back at the
     * source resource, not one-of-many, not Through; the one named by
     * `inverse()` when the field sets it) already holds a record. On an
     * update Nova lets the write through when the record has no target yet
     * or keeps the one it has, and so does Martis. Only the source
     * resource's own record is checked (its forms and a panel's inline
     * forms), not a pivot row, a Repeater row or an Action.
     *
     * @param  class-string<\Martis\Resource>  $relatedResourceClass
     */
    private function relationshipIsFull(string $relatedResourceClass, Model $related): bool
    {
        $field = $this->field;

        if ($this->inRow || $this->sourceResourceClass === null || (! $field instanceof BelongsTo && ! $field instanceof MorphTo)) {
            return false;
        }

        $record = $this->record;
        $sourceModel = $this->sourceResourceClass::newModel();

        if (! $record instanceof $sourceModel) {
            return false;
        }

        $inverse = $this->inverseField($relatedResourceClass, $field instanceof BelongsTo ? $field->getInverse() : null);

        if ($inverse === null) {
            return false;
        }

        if ($record->exists) {
            $current = $record->getAttribute($field instanceof BelongsTo ? $field->attribute() : $field->getMorphIdColumn());

            if ($current === null || (string) $current === (string) $related->getKey()) {
                return false;
            }
        }

        $relationship = $inverse->getRelationship();

        if (! method_exists($related, $relationship)) {
            return false;
        }

        $relation = $related->{$relationship}();

        return $relation instanceof Relation && $relation->count() > 0;
    }

    /**
     * The related resource's `HasOne` / `MorphOne` field that points back
     * at the source resource, as Nova's `resolveInverseFieldsForAttribute()`
     * finds it.
     *
     * @param  class-string<\Martis\Resource>  $relatedResourceClass
     */
    private function inverseField(string $relatedResourceClass, ?string $inverse): HasOne|MorphOne|null
    {
        /** @var class-string<\Martis\Resource> $sourceResourceClass */
        $sourceResourceClass = $this->sourceResourceClass;
        $sourceUriKey = $sourceResourceClass::uriKey();
        $related = new $relatedResourceClass($relatedResourceClass::newModel());

        foreach (Field::flattenLayoutFields($related->fields($this->request)) as $candidate) {
            if (! $candidate instanceof HasOne && ! $candidate instanceof MorphOne) {
                continue;
            }

            if ($candidate instanceof HasOneOfMany || $candidate instanceof MorphOneOfMany || $candidate instanceof HasOneThrough) {
                continue;
            }

            if ($candidate->getRelatedResourceKey() !== $sourceUriKey) {
                continue;
            }

            if ($inverse !== null && $candidate->getRelationship() !== $inverse) {
                continue;
            }

            return $candidate;
        }

        return null;
    }

    /**
     * The policy abilities the write needs for one related record.
     * `$attaching` holds the ids a `Tag` adds.
     *
     * @param  class-string<\Martis\Resource>  $relatedResourceClass
     * @param  array<string, int|string>  $attaching
     */
    private function authorized(string $relatedResourceClass, Model $related, array $attaching): bool
    {
        if ($this->sourceResourceClass === null) {
            return true;
        }

        if ($this->field instanceof Tag) {
            // A Tag in a Repeater row attaches nothing, and a record the
            // relationship already holds is not attached again.
            if ($this->inRow || ! array_key_exists((string) $related->getKey(), $attaching)) {
                return true;
            }

            $source = $this->sourceResource();

            return $source === null || ($source->authorizedToAttachAny($this->request, $related::class)
                && $source->authorizedToAttach($this->request, $related));
        }

        /** @var class-string<Model> $sourceModelClass */
        $sourceModelClass = $this->sourceResourceClass::model();

        return (new $relatedResourceClass($related))->authorizedToAdd($this->request, $sourceModelClass);
    }

    /**
     * The source resource on the record a `Tag` syncs on: the record the
     * write fills, or a new model among pivot fields, where the record is
     * the pivot row.
     */
    private function sourceResource(): ?\Martis\Resource
    {
        if ($this->sourceResourceClass === null || $this->inRow) {
            return null;
        }

        $sourceModel = $this->sourceResourceClass::newModel();
        $record = $this->record instanceof $sourceModel ? $this->record : $sourceModel;

        return new ($this->sourceResourceClass)($record);
    }

    /**
     * The records a `Tag` relationship of the record holds, keyed by key.
     *
     * @return array<string, Model>
     */
    private function syncedModels(Tag $field): array
    {
        $record = $this->record;

        if ($this->inRow || $record === null || ! $record->exists) {
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

        $synced = [];
        foreach ($relation->get() as $model) {
            $synced[(string) $model->getKey()] = $model;
        }

        return $synced;
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
