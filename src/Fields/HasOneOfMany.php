<?php

namespace Martis\Fields;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany as EloquentHasMany;
use Illuminate\Database\Eloquent\Relations\HasOne as EloquentHasOne;
use Martis\Enums\AggregateFunction;

/**
 * HasOneOfMany — "has one of many" relationship field.
 *
 * Laravel Nova v5 parity: HasOneOfMany.
 * Reference: https://nova.laravel.com/docs/v5/resources/relationships#HasOneOfMany
 *
 * The relationship on the parent model is defined as
 *   `hasMany(Target::class)->latestOfMany()` / `->oldestOfMany()` / `->ofMany(...)`
 * and Nova / Martis renders the promoted record as if it were a plain `HasOne`.
 * Visually identical to `HasOne`; differs only in the backing Eloquent shape.
 *
 * ⭐ Martis differentials:
 *  - **`latestByTimestamp('column')` / `oldestByTimestamp('column')`** —
 *    one-liner configuration. Resolves the latest/oldest record using
 *    the given timestamp column, adjusting the relationship query at
 *    runtime so the resource doesn't need a dedicated `latestFoo()`
 *    method on the model.
 *  - **"Latest of N" affordance** — the field ships a small metadata
 *    pill ("latest of 12") under the promoted record on the detail
 *    panel, computed from `COUNT(*)` on the underlying `hasMany`.
 *  - **`aggregateVia(AggregateFunction, 'column')`** — ships a metric
 *    tile alongside the promoted record (e.g. latest invoice + total
 *    billed lifetime). Supports count / sum / min / max / avg.
 */
class HasOneOfMany extends HasOne
{
    /**
     * Aggregate function to apply across the full HasMany collection
     * (⭐ Martis differential — Nova does not ship this).
     */
    protected ?AggregateFunction $aggregateFunction = null;

    /** Column fed to the aggregate. */
    protected ?string $aggregateColumn = null;

    /**
     * Optional runtime scope applied to the relationship before
     * Eloquent's own `ofMany()` resolves. Set by
     * `latestByTimestamp()` / `oldestByTimestamp()`.
     */
    protected ?Closure $runtimeScope = null;

    public function type(): string
    {
        return 'has_one_of_many';
    }

    /**
     * Validate that the relationship method on the parent model returns
     * an Eloquent `HasOne` (which is what `->latestOfMany()` returns) or
     * an Eloquent `HasMany` (which we can downgrade with a runtime scope).
     */
    public function validateRelationship(Model $model): void
    {
        if (! method_exists($model, $this->relationship)) {
            throw new \InvalidArgumentException(
                'Model '.get_class($model)." does not define relationship method '{$this->relationship}'."
            );
        }

        $relation = $model->{$this->relationship}();

        if (! $relation instanceof EloquentHasOne && ! $relation instanceof EloquentHasMany) {
            throw new \InvalidArgumentException(
                "Relationship '{$this->relationship}' on ".get_class($model).' must be hasOne()->latestOfMany() / ofMany() or a plain hasMany() compatible with ofMany().'
            );
        }
    }

    /**
     * ⭐ Martis differential — configure the promotion using the given
     * timestamp column, implicitly emitting `latestOfMany($column)`.
     * Avoids having to declare a dedicated `latestFoo()` on the model.
     */
    public function latestByTimestamp(string $column = 'created_at'): static
    {
        $this->runtimeScope = static function (Builder $query) use ($column): Builder {
            return $query->orderByDesc($column);
        };

        return $this;
    }

    /**
     * ⭐ Martis differential — oldest counterpart of latestByTimestamp().
     */
    public function oldestByTimestamp(string $column = 'created_at'): static
    {
        $this->runtimeScope = static function (Builder $query) use ($column): Builder {
            return $query->orderBy($column);
        };

        return $this;
    }

    /**
     * ⭐ Martis differential — emit an aggregate metric tile alongside
     * the promoted record on the detail panel.
     *
     * `aggregateVia(AggregateFunction::Sum, 'amount')` ships
     * `{ fn: 'sum', column: 'amount' }` to the frontend; the resolver
     * runs the query and adds the computed value to the field payload.
     */
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

    /**
     * Runtime scope applied by the controller when resolving this field.
     * Returns null when neither `latestByTimestamp()` nor
     * `oldestByTimestamp()` is configured.
     */
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
