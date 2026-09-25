<?php

namespace Martis\Fields\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Martis\Resource;
use Martis\ResourceRegistry;
use Martis\Support\RelationScope;

/**
 * The index value of a to-many relationship field (`showOnIndex()`): the
 * number of related records, counted as the related resource's index would
 * list them (its `scopes()` and `indexQuery()`, see `RelationScope`), so a
 * hidden record is neither shown nor counted. The listing aggregates the
 * counts in its own query (`MartisController::withScopedRelationCounts()`)
 * under `countAlias()`; a model loaded without it counts on its own.
 *
 * `HasMany` (and `HasManyThrough`), `MorphMany`, `BelongsToMany` and
 * `MorphToMany` use it.
 */
trait CountsScopedRelation
{
    public function countsOnIndex(): bool
    {
        return $this->showOnIndex;
    }

    /** The attribute the listing's `withCount()` writes this field's count to. */
    public function countAlias(): string
    {
        return 'martis_count_'.Str::snake($this->relationship);
    }

    /** @return class-string<resource>|null */
    public function relatedResourceClassForCount(): ?string
    {
        $key = $this->getRelatedResourceKey();
        $registry = app(ResourceRegistry::class);

        if ($key === null || ! $registry->has($key)) {
            return null;
        }

        /** @var class-string<resource> $class */
        $class = $registry->get($key);

        return $class;
    }

    protected function scopedRelationCount(Model $model): int
    {
        $attributes = $model->getAttributes();

        if (array_key_exists($this->countAlias(), $attributes)) {
            return (int) $attributes[$this->countAlias()];
        }

        $relation = $model->{$this->relationship}();
        $relatedResourceClass = $this->relatedResourceClassForCount();

        if ($relatedResourceClass !== null) {
            RelationScope::constrainByKey(request(), $relation->getQuery(), $relatedResourceClass);
        }

        return $relation->count();
    }
}
