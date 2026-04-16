<?php

namespace Martis\Metrics;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * A metric that displays grouped data as a pie or donut chart.
 *
 * Nova v5 parity: count, sum, average by group with colors and labels.
 *
 * @phpstan-consistent-constructor
 */
abstract class PartitionMetric extends Metric
{
    public function metricType(): string
    {
        return 'partition';
    }

    /**
     * Partition metrics typically don't use time ranges.
     */
    public function ranges(): array
    {
        return [];
    }

    // -------------------------------------------------------------------------
    // Query helpers
    // -------------------------------------------------------------------------

    /**
     * Count records grouped by a column.
     *
     * @param  class-string<Model>  $model
     */
    protected function count(Request $request, string $model, string $groupBy): PartitionResult
    {
        return $this->aggregate($request, $model, 'count', null, $groupBy);
    }

    /**
     * Sum a column grouped by another column.
     *
     * @param  class-string<Model>  $model
     */
    protected function sum(Request $request, string $model, string $column, string $groupBy): PartitionResult
    {
        return $this->aggregate($request, $model, 'sum', $column, $groupBy);
    }

    /**
     * Average a column grouped by another column.
     *
     * @param  class-string<Model>  $model
     */
    protected function average(Request $request, string $model, string $column, string $groupBy): PartitionResult
    {
        return $this->aggregate($request, $model, 'avg', $column, $groupBy);
    }

    /**
     * Create a result with manual data.
     *
     * @param  array<string, float|int>  $data  Label => value pairs
     */
    protected function result(array $data): PartitionResult
    {
        return new PartitionResult($data);
    }

    /**
     * @param  class-string<Model>  $model
     */
    protected function aggregate(Request $request, string $model, string $function, ?string $column, string $groupBy): PartitionResult
    {
        $expression = $function === 'count'
            ? DB::raw('count(*) as aggregate')
            : DB::raw("{$function}({$column}) as aggregate");

        /** @var array<string, float|int> $results */
        $results = $this->applyFilterScope($model::query())
            ->select($groupBy, $expression)
            ->groupBy($groupBy)
            ->orderByDesc('aggregate')
            ->pluck('aggregate', $groupBy)
            ->all();

        return new PartitionResult($results);
    }
}
