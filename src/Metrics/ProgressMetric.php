<?php

namespace Martis\Metrics;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Martis\Enums\MetricType;

/**
 * A metric that displays progress toward a target as a progress bar.
 *
 * Supports current vs target with optional avoid() for minimization.
 *
 * @phpstan-consistent-constructor
 */
abstract class ProgressMetric extends Metric
{
    public function metricType(): MetricType
    {
        return MetricType::Progress;
    }

    /**
     * Progress metrics typically don't use time ranges.
     */
    public function ranges(): array
    {
        return [];
    }

    // -------------------------------------------------------------------------
    // Query helpers
    // -------------------------------------------------------------------------

    /**
     * Count records matching a condition toward a target.
     *
     * @param  class-string<Model>  $model
     * @param  \Closure(Builder<Model>): Builder<Model>  $progress  Query scope for current progress
     */
    protected function count(Request $request, string $model, \Closure $progress, float|int $target): ProgressResult
    {
        $current = $progress($this->applyFilterScope($model::query()))->count();

        return new ProgressResult((float) $current, (float) $target);
    }

    /**
     * Sum a column toward a target.
     *
     * @param  class-string<Model>  $model
     * @param  \Closure(Builder<Model>): Builder<Model>  $progress
     */
    protected function sum(Request $request, string $model, \Closure $progress, string $column, float|int $target): ProgressResult
    {
        $current = $progress($this->applyFilterScope($model::query()))->sum($column);

        return new ProgressResult((float) ($current ?? 0), (float) $target);
    }

    /**
     * Create a result with manual values.
     */
    protected function result(float|int $current, float|int $target): ProgressResult
    {
        return new ProgressResult($current, $target);
    }
}
