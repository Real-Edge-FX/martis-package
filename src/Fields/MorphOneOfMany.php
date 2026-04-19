<?php

namespace Martis\Fields;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany as EloquentMorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne as EloquentMorphOne;
use Martis\Enums\AggregateFunction;

/**
 * MorphOneOfMany — "morph one of many" polymorphic relationship field.
 *
 * Laravel Nova v5 parity: MorphOneOfMany.
 * Reference: https://nova.laravel.com/docs/v5/resources/relationships#MorphOneOfMany
 *
 * Polymorphic counterpart of {@see HasOneOfMany}. Used when the parent
 * model defines a `morphMany(...)->latestOfMany()` / `->ofMany(...)`
 * relationship (e.g. "latest comment on a post").
 *
 * ⭐ Martis differentials — same set as HasOneOfMany:
 *  - `latestByTimestamp('col')` / `oldestByTimestamp('col')`.
 *  - "Latest of N" affordance on the detail panel.
 *  - `aggregateVia(AggregateFunction::*, 'col')` metric tile.
 */
class MorphOneOfMany extends MorphOne
{
    protected ?AggregateFunction $aggregateFunction = null;

    protected ?string $aggregateColumn = null;

    protected ?Closure $runtimeScope = null;

    public function type(): string
    {
        return 'morph_one_of_many';
    }

    /**
     * Validate that the relationship method on the parent model returns
     * an Eloquent `MorphOne` (what `->latestOfMany()` returns) or a
     * `MorphMany` (which we can scope at runtime).
     */
    public function validateRelationship(Model $model): void
    {
        if (! method_exists($model, $this->relationship)) {
            throw new \InvalidArgumentException(
                'Model '.get_class($model)." does not define relationship method '{$this->relationship}'."
            );
        }

        $relation = $model->{$this->relationship}();

        if (! $relation instanceof EloquentMorphOne && ! $relation instanceof EloquentMorphMany) {
            throw new \InvalidArgumentException(
                "Relationship '{$this->relationship}' on ".get_class($model).' must be morphOne()->latestOfMany() / ofMany() or a plain morphMany() compatible with ofMany().'
            );
        }
    }

    /** ⭐ Martis differential — configure via timestamp column. */
    public function latestByTimestamp(string $column = 'created_at'): static
    {
        $this->runtimeScope = static function (Builder $query) use ($column): Builder {
            return $query->orderByDesc($column);
        };

        return $this;
    }

    /** ⭐ Martis differential. */
    public function oldestByTimestamp(string $column = 'created_at'): static
    {
        $this->runtimeScope = static function (Builder $query) use ($column): Builder {
            return $query->orderBy($column);
        };

        return $this;
    }

    /** ⭐ Martis differential — aggregate tile alongside promoted record. */
    public function aggregateVia(AggregateFunction $function, string $column = '*'): static
    {
        $this->aggregateFunction = $function;
        $this->aggregateColumn = $column;

        return $this;
    }

    public function getAggregateFunction(): ?AggregateFunction
    {
        return $this->aggregateFunction;
    }

    public function getAggregateColumn(): ?string
    {
        return $this->aggregateColumn;
    }

    public function getRuntimeScope(): ?Closure
    {
        return $this->runtimeScope;
    }

    /**
     * @return array<string, mixed>
     */
    protected function extraAttributes(): array
    {
        $base = parent::extraAttributes();

        $extras = [
            'ofManyMeta' => [
                'aggregate' => $this->aggregateFunction !== null
                    ? [
                        'fn' => $this->aggregateFunction->value,
                        'column' => $this->aggregateColumn,
                    ]
                    : null,
            ],
        ];

        return $base + $extras;
    }
}
