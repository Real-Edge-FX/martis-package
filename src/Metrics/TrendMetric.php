<?php

namespace Martis\Metrics;

use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Martis\Enums\AggregateFunction;
use Martis\Enums\MetricType;
use Martis\Enums\TrendPeriod;

/**
 * A metric that displays time-series data as a line or bar chart.
 *
 * Supports countByDays, countByWeeks, countByMonths, sumByDays, averageByDays.
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

        // Fetch the rows in range and bucket them in PHP. This is correct
        // on every database driver (MySQL / Postgres / SQLite) without any
        // dialect-specific date function: the previous implementation used
        // MySQL-only `DATE_FORMAT(...)`, which is `no such function` on
        // SQLite and Postgres (500 on every non-MySQL trend metric). The
        // bucket key is built with the SAME Carbon formats as the label
        // loop below, so there is no SQL-vs-PHP key mismatch — notably for
        // ISO week (`o-W`), which SQLite's strftime cannot express at all.
        // Trend ranges are bounded, so the fetched row set is small.
        $baseQuery = $this->applyFilterScope($model::query())
            ->where($dateColumn, '>=', $startDate)
            ->where($dateColumn, '<=', $now);

        $columns = [$dateColumn];
        if ($function !== AggregateFunction::Count && $column !== null) {
            $columns[] = $column;
        }

        /** @var array<string, list<float>> $bucketValues */
        $bucketValues = [];

        foreach ($baseQuery->get($columns) as $row) {
            $rawDate = $row->getAttribute($dateColumn);
            if ($rawDate === null) {
                continue;
            }

            $date = $rawDate instanceof \DateTimeInterface
                ? CarbonImmutable::instance($rawDate)
                : CarbonImmutable::parse((string) $rawDate);

            $key = match ($unit) {
                TrendPeriod::Day => $date->format('Y-m-d'),
                TrendPeriod::Week => $date->format('o-W'),
                TrendPeriod::Month => $date->format('Y-m'),
            };

            $bucketValues[$key][] = ($function === AggregateFunction::Count || $column === null)
                ? 1.0
                : (float) ($row->getAttribute($column) ?? 0);
        }

        /** @var array<string, float> $results */
        $results = [];
        foreach ($bucketValues as $key => $vals) {
            $results[$key] = match ($function) {
                AggregateFunction::Count => (float) count($vals),
                AggregateFunction::Sum => array_sum($vals),
                AggregateFunction::Avg => $vals === [] ? 0.0 : array_sum($vals) / count($vals),
                AggregateFunction::Min => $vals === [] ? 0.0 : min($vals),
                AggregateFunction::Max => $vals === [] ? 0.0 : max($vals),
            };
        }

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
