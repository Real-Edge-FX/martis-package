<?php

namespace Martis\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Martis\Models\ActionEvent;

/**
 * The action event log of a pivot action.
 *
 * One row per selected related record, with Nova's mapping for pivot
 * actions: the record whose relationship panel ran the action is the
 * actionable, the related record is the target, and its pivot row is the
 * model. `original` / `changes` hold the columns of the pivot row the action
 * changed, read from the pivot table before and after the run.
 */
final class PivotActionEventLog
{
    /**
     * The pivot rows of the given related models, keyed by the related key
     * the pivot row stores.
     *
     * @param  BelongsToMany<Model, Model, covariant Pivot, covariant string>  $relation
     * @param  Collection<int, Model>  $models
     * @return array<string, array<string, mixed>>
     */
    public static function pivotRows(BelongsToMany $relation, Collection $models): array
    {
        if ($models->isEmpty()) {
            return [];
        }

        $relatedPivotKey = $relation->getRelatedPivotKeyName();
        $relatedKeys = $models->map(fn (Model $model): mixed => $model->getAttribute($relation->getRelatedKeyName()))->all();

        $rows = [];
        foreach ($relation->newPivotQuery()->whereIn($relatedPivotKey, $relatedKeys)->get() as $row) {
            $attributes = (array) $row;
            $rows[(string) $attributes[$relatedPivotKey]] ??= $attributes;
        }

        return $rows;
    }

    /**
     * Write one event per related model.
     *
     * @param  BelongsToMany<Model, Model, covariant Pivot, covariant string>  $relation
     * @param  Collection<int, Model>  $models
     * @param  array<string, mixed>  $fields
     * @param  array<string, array<string, mixed>>  $before
     * @param  array<string, array<string, mixed>>  $after
     */
    public static function record(
        Action $action,
        Model $parent,
        BelongsToMany $relation,
        Collection $models,
        int|string|null $userId,
        array $fields,
        string $status,
        ?string $exception = null,
        array $before = [],
        array $after = [],
    ): void {
        try {
            $batchId = (string) Str::uuid();

            foreach ($models as $model) {
                $pivot = self::pivotOf($relation, $model);
                [$original, $changes] = self::diff($before, $after, $relation, $model);

                ActionEvent::create([
                    'batch_id' => $batchId,
                    'user_id' => $userId,
                    'name' => $action->name(),
                    'actionable_type' => get_class($parent),
                    'actionable_id' => $parent->getKey(),
                    'target_type' => get_class($model),
                    'target_id' => $model->getKey(),
                    'model_type' => $pivot !== null ? get_class($pivot) : $relation->getPivotClass(),
                    'model_id' => $pivot?->getKey(),
                    'fields' => $fields,
                    'status' => $status,
                    'exception' => $exception ?? '',
                    'original' => $original,
                    'changes' => $changes,
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('Failed to log pivot action event', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Settle the `queued` events a queued pivot action wrote, one per related
     * model, with its final status and the pivot diff of the run.
     *
     * @param  BelongsToMany<Model, Model, covariant Pivot, covariant string>  $relation
     * @param  Collection<int, Model>  $models
     * @param  array<string, array<string, mixed>>  $before
     * @param  array<string, array<string, mixed>>  $after
     */
    public static function settle(
        Action $action,
        Model $parent,
        BelongsToMany $relation,
        Collection $models,
        string $status,
        ?string $exception = null,
        array $before = [],
        array $after = [],
    ): void {
        try {
            foreach ($models as $model) {
                [$original, $changes] = self::diff($before, $after, $relation, $model);

                ActionEvent::query()
                    ->where('name', $action->name())
                    ->where('actionable_type', get_class($parent))
                    ->where('actionable_id', $parent->getKey())
                    ->where('target_type', get_class($model))
                    ->where('target_id', $model->getKey())
                    ->where('status', 'queued')
                    ->orderByDesc('id')
                    ->limit(1)
                    ->update([
                        'status' => $status,
                        'exception' => $exception ?? '',
                        // A query-builder update: the model's array cast does
                        // not run, so the JSON columns are encoded here.
                        'original' => json_encode($original),
                        'changes' => json_encode($changes),
                    ]);
            }
        } catch (\Throwable $e) {
            Log::warning('Failed to settle pivot action events', ['error' => $e->getMessage()]);
        }
    }

    /**
     * The pivot model a related model was loaded with, when the relationship
     * query attached one.
     *
     * @param  BelongsToMany<Model, Model, covariant Pivot, covariant string>  $relation
     */
    private static function pivotOf(BelongsToMany $relation, Model $model): ?Model
    {
        $accessor = $relation->getPivotAccessor();
        $pivot = $model->relationLoaded($accessor) ? $model->getRelation($accessor) : null;

        return $pivot instanceof Model ? $pivot : null;
    }

    /**
     * The columns of one pivot row that the run changed: their values before
     * and after.
     *
     * @param  array<string, array<string, mixed>>  $before
     * @param  array<string, array<string, mixed>>  $after
     * @param  BelongsToMany<Model, Model, covariant Pivot, covariant string>  $relation
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private static function diff(array $before, array $after, BelongsToMany $relation, Model $model): array
    {
        $key = (string) $model->getAttribute($relation->getRelatedKeyName());
        $old = $before[$key] ?? [];
        $new = $after[$key] ?? [];

        $original = [];
        $changes = [];
        foreach ($new as $column => $value) {
            if (array_key_exists($column, $old) && $old[$column] != $value) {
                $original[$column] = $old[$column];
                $changes[$column] = $value;
            }
        }

        return [$original, $changes];
    }
}
