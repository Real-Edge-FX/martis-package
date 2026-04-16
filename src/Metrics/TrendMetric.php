<?php

namespace Martis\Metrics;

use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * A metric that displays time-series data as a line or bar chart.
 *
 * Nova v5 parity: countByDays, countByWeeks, countByMonths, sumByDays, averageByDays.
 *
 * @phpstan-consistent-constructor
 */
abstract class TrendMetric extends Metric
{
    public function metricType(): string
    {
        return 'trend';
    }

    // -------------------------------------------------------------------------
    // Count helpers
    // -------------------------------------------------------------------------

    /**
     * @param  class-string<Model>  $model
     */
    protected function countByDays(Request $request, string $model, ?string $dateColumn = null): TrendResult
    {
        return $this->aggregateByPeriod($request, $model, 'count', null, $dateColumn, 'day');
    }

    /**
     * @param  class-string<Model>  $model
     */
    protected function countByWeeks(Request $request, string $model, ?string $dateColumn = null): TrendResult
    {
        return $this->aggregateByPeriod($request, $model, 'count', null, $dateColumn, 'week');
    }

    /**
     * @param  class-string<Model>  $model
     */
    protected function countByMonths(Request $request, string $model, ?string $dateColumn = null): TrendResult
    {
        return $this->aggregateByPeriod($request, $model, 'count', null, $dateColumn, 'month');
    }

    // -------------------------------------------------------------------------
    // Sum helpers
    // -------------------------------------------------------------------------

    /**
     * @param  class-string<Model>  $model
     */
    protected function sumByDays(Request $request, string $model, string $column, ?string $dateColumn = null): TrendResult
    {
        return $this->aggregateByPeriod($request, $model, 'sum', $column, $dateColumn, 'day');
    }

    /**
     * @param  class-string<Model>  $model
     */
    protected function sumByWeeks(Request $request, string $model, string $column, ?string $dateColumn = null): TrendResult
    {
        return $this->aggregateByPeriod($request, $model, 'sum', $column, $dateColumn, 'week');
    }

    /**
     * @param  class-string<Model>  $model
     */
    protected function sumByMonths(Request $request, string $model, string $column, ?string $dateColumn = null): TrendResult
    {
        return $this->aggregateByPeriod($request, $model, 'sum', $column, $dateColumn, 'month');
    }

    // -------------------------------------------------------------------------
    // Average helpers
    // -------------------------------------------------------------------------

    /**
     * @param  class-string<Model>  $model
     */
    protected function averageByDays(Request $request, string $model, string $column, ?string $dateColumn = null): TrendResult
    {
        return $this->aggregateByPeriod($request, $model, 'avg', $column, $dateColumn, 'day');
    }

    /**
     * @param  class-string<Model>  $model
     */
    protected function averageByMonths(Request $request, string $model, string $column, ?string $dateColumn = null): TrendResult
    {
        return $this->aggregateByPeriod($request, $model, 'avg', $column, $dateColumn, 'month');
    }

    /**
     * Create a manual result.
     *
     * @param  list<string>  $labels
     * @param  list<float|int>  $values
     */
    protected function result(array $labels, array $values): TrendResult
    {
        return new TrendResult($labels, $values);
    }

    // -------------------------------------------------------------------------
    // Internal aggregate engine
    // -------------------------------------------------------------------------

    /**
     * @param  class-string<Model>  $model
     */
    protected function aggregateByPeriod(
        Request $request,
        string $model,
        string $function,
        ?string $column,
        ?string $dateColumn,
        string $unit,
    ): TrendResult {
        $dateColumn = $dateColumn ?? 'created_at';
        $range = (int) $request->query('range', '30');
        $now = CarbonImmutable::now();

        $startDate = match ($unit) {
            'day' => $now->subDays($range)->startOfDay(),
            'week' => $now->subWeeks($range)->startOfWeek(),
            'month' => $now->subMonths($range)->startOfMonth(),
            default => $now->subDays($range)->startOfDay(),
        };

        $dateFormat = match ($unit) {
            'day' => '%Y-%m-%d',
            'week' => '%x-%v',
            'month' => '%Y-%m',
            default => '%Y-%m-%d',
        };

        $expression = $function === 'count'
            ? DB::raw('count(*) as aggregate')
            : DB::raw("{$function}({$column}) as aggregate");

        $baseQuery = $this->applyFilterScope($model::query());

        $results = $baseQuery
            ->select(DB::raw("DATE_FORMAT({$dateColumn}, '{$dateFormat}') as date_key"), $expression)
            ->where($dateColumn, '>=', $startDate)
            ->where($dateColumn, '<=', $now)
            ->groupBy('date_key')
            ->orderBy('date_key')
            ->pluck('aggregate', 'date_key')
            ->all();

        // Build complete series with zeroes for missing periods
        $labels = [];
        $values = [];
        $period = CarbonPeriod::create($startDate, "1 {$unit}", $now);

        foreach ($period as $date) {
            $key = match ($unit) {
                'day' => $date->format('Y-m-d'),
                'week' => $date->format('o-W'),
                'month' => $date->format('Y-m'),
                default => $date->format('Y-m-d'),
            };

            $labelFormat = match ($unit) {
                'day' => $date->format('M d'),
                'week' => 'W'.$date->format('W'),
                'month' => $date->format('M Y'),
                default => $date->format('M d'),
            };

            $labels[] = $labelFormat;
            $values[] = (float) ($results[$key] ?? 0);
        }

        return new TrendResult($labels, $values);
    }
}
