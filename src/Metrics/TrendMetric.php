<?php

namespace Martis\Metrics;

use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Martis\Enums\AggregateFunction;
use Martis\Enums\MetricType;
use Martis\Enums\TrendPeriod;

/**
 * A metric that displays time-series data as a line or bar chart.
 *
 * Nova v5 parity: countByDays, countByWeeks, countByMonths, sumByDays, averageByDays.
 *
 * @phpstan-consistent-constructor
 */
abstract class TrendMetric extends Metric
{
    public function metricType(): MetricType
    {
        return MetricType::Trend;
    }

    // -------------------------------------------------------------------------
    // Count helpers
    // -------------------------------------------------------------------------

    /**
     * @param  class-string<Model>  $model
     */
    protected function countByDays(Request $request, string $model, ?string $dateColumn = null): TrendResult
    {
        return $this->aggregateByPeriod($request, $model, AggregateFunction::Count, null, $dateColumn, TrendPeriod::Day);
    }

    /**
     * @param  class-string<Model>  $model
     */
    protected function countByWeeks(Request $request, string $model, ?string $dateColumn = null): TrendResult
    {
        return $this->aggregateByPeriod($request, $model, AggregateFunction::Count, null, $dateColumn, TrendPeriod::Week);
    }

    /**
     * @param  class-string<Model>  $model
     */
    protected function countByMonths(Request $request, string $model, ?string $dateColumn = null): TrendResult
    {
        return $this->aggregateByPeriod($request, $model, AggregateFunction::Count, null, $dateColumn, TrendPeriod::Month);
    }

    // -------------------------------------------------------------------------
    // Sum helpers
    // -------------------------------------------------------------------------

    /**
     * @param  class-string<Model>  $model
     */
    protected function sumByDays(Request $request, string $model, string $column, ?string $dateColumn = null): TrendResult
    {
        return $this->aggregateByPeriod($request, $model, AggregateFunction::Sum, $column, $dateColumn, TrendPeriod::Day);
    }

    /**
     * @param  class-string<Model>  $model
     */
    protected function sumByWeeks(Request $request, string $model, string $column, ?string $dateColumn = null): TrendResult
    {
        return $this->aggregateByPeriod($request, $model, AggregateFunction::Sum, $column, $dateColumn, TrendPeriod::Week);
    }

    /**
     * @param  class-string<Model>  $model
     */
    protected function sumByMonths(Request $request, string $model, string $column, ?string $dateColumn = null): TrendResult
    {
        return $this->aggregateByPeriod($request, $model, AggregateFunction::Sum, $column, $dateColumn, TrendPeriod::Month);
    }

    // -------------------------------------------------------------------------
    // Average helpers
    // -------------------------------------------------------------------------

    /**
     * @param  class-string<Model>  $model
     */
    protected function averageByDays(Request $request, string $model, string $column, ?string $dateColumn = null): TrendResult
    {
        return $this->aggregateByPeriod($request, $model, AggregateFunction::Avg, $column, $dateColumn, TrendPeriod::Day);
    }

    /**
     * @param  class-string<Model>  $model
     */
    protected function averageByMonths(Request $request, string $model, string $column, ?string $dateColumn = null): TrendResult
    {
        return $this->aggregateByPeriod($request, $model, AggregateFunction::Avg, $column, $dateColumn, TrendPeriod::Month);
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
        AggregateFunction $function,
        ?string $column,
        ?string $dateColumn,
        TrendPeriod $unit,
    ): TrendResult {
        $dateColumn = $dateColumn ?? 'created_at';
        $range = (int) $request->query('range', '30');
        $now = CarbonImmutable::now();

        $startDate = match ($unit) {
            TrendPeriod::Day => $now->subDays($range)->startOfDay(),
            TrendPeriod::Week => $now->subWeeks($range)->startOfWeek(),
            TrendPeriod::Month => $now->subMonths($range)->startOfMonth(),
        };

        $dateFormat = match ($unit) {
            TrendPeriod::Day => '%Y-%m-%d',
            TrendPeriod::Week => '%x-%v',
            TrendPeriod::Month => '%Y-%m',
        };

        $expression = $function === AggregateFunction::Count
            ? DB::raw('count(*) as aggregate')
            : DB::raw("{$function->value}({$column}) as aggregate");

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
        $period = CarbonPeriod::create($startDate, "1 {$unit->value}", $now);

        foreach ($period as $date) {
            $key = match ($unit) {
                TrendPeriod::Day => $date->format('Y-m-d'),
                TrendPeriod::Week => $date->format('o-W'),
                TrendPeriod::Month => $date->format('Y-m'),
            };

            $labelFormat = match ($unit) {
                TrendPeriod::Day => $date->format('M d'),
                TrendPeriod::Week => 'W'.$date->format('W'),
                TrendPeriod::Month => $date->format('M Y'),
            };

            $labels[] = $labelFormat;
            $values[] = (float) ($results[$key] ?? 0);
        }

        return new TrendResult($labels, $values);
    }
}
