<?php

namespace Martis\Metrics;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Martis\Enums\AggregateFunction;
use Martis\Enums\MetricRange;
use Martis\Enums\MetricType;

/**
 * A metric that displays a single value with optional period comparison.
 *
 * Supports count, sum, average, max, min with ranges and formatting.
 *
 * @phpstan-consistent-constructor
 */
abstract class ValueMetric extends Metric
{
    public function metricType(): MetricType
    {
        return MetricType::Value;
    }

    // -------------------------------------------------------------------------
    // Query helpers
    // -------------------------------------------------------------------------

    /**
     * Count records for the current and previous periods.
     *
     * @param  class-string<Model>  $model
     */
    protected function count(Request $request, string $model, ?string $dateColumn = null): ValueResult
    {
        return $this->aggregate($request, $model, AggregateFunction::Count, null, $dateColumn);
    }

    /**
     * Sum a column for the current and previous periods.
     *
     * @param  class-string<Model>  $model
     */
    protected function sum(Request $request, string $model, string $column, ?string $dateColumn = null): ValueResult
    {
        return $this->aggregate($request, $model, AggregateFunction::Sum, $column, $dateColumn);
    }

    /**
     * Average a column for the current and previous periods.
     *
     * @param  class-string<Model>  $model
     */
    protected function average(Request $request, string $model, string $column, ?string $dateColumn = null): ValueResult
    {
        return $this->aggregate($request, $model, AggregateFunction::Avg, $column, $dateColumn);
    }

    /**
     * Max of a column for the current and previous periods.
     *
     * @param  class-string<Model>  $model
     */
    protected function max(Request $request, string $model, string $column, ?string $dateColumn = null): ValueResult
    {
        return $this->aggregate($request, $model, AggregateFunction::Max, $column, $dateColumn);
    }

    /**
     * Min of a column for the current and previous periods.
     *
     * @param  class-string<Model>  $model
     */
    protected function min(Request $request, string $model, string $column, ?string $dateColumn = null): ValueResult
    {
        return $this->aggregate($request, $model, AggregateFunction::Min, $column, $dateColumn);
    }

    /**
     * Create a result with a manual value.
     */
    protected function result(float|int $value): ValueResult
    {
        return new ValueResult($value);
    }

    /**
     * Run the aggregate query for current and previous periods.
     *
     * @param  class-string<Model>  $model
     */
    protected function aggregate(Request $request, string $model, AggregateFunction $function, ?string $column, ?string $dateColumn): ValueResult
    {
        $dateColumn = $dateColumn ?? 'created_at';
        $range = $request->query('range', '30');
        [$currentStart, $currentEnd, $previousStart, $previousEnd] = $this->calculateDateRange($range);

        $currentQuery = $this->applyFilterScope(
            $model::query()->whereBetween($dateColumn, [$currentStart, $currentEnd])
        );

        $previousQuery = $this->applyFilterScope(
            $model::query()->whereBetween($dateColumn, [$previousStart, $previousEnd])
        );

        $currentValue = $function === AggregateFunction::Count
            ? $currentQuery->count()
            : $currentQuery->{$function->value}($column);

        $previousValue = $function === AggregateFunction::Count
            ? $previousQuery->count()
            : $previousQuery->{$function->value}($column);

        return (new ValueResult((float) ($currentValue ?? 0)))
            ->previous((float) ($previousValue ?? 0));
    }

    /**
     * Calculate date ranges for current and previous periods.
     *
     * Accepts a {@see MetricRange} case OR an integer day window (e.g. 30).
     *
     * @return array{CarbonImmutable, CarbonImmutable, CarbonImmutable, CarbonImmutable}
     */
    protected function calculateDateRange(MetricRange|string|int $range): array
    {
        $now = CarbonImmutable::now();
        $resolved = $range instanceof MetricRange
            ? $range
            : MetricRange::tryFrom((string) $range);

        return match ($resolved) {
            MetricRange::Today => [
                $now->startOfDay(), $now,
                $now->subDay()->startOfDay(), $now->subDay()->endOfDay(),
            ],
            MetricRange::MonthToDate => [
                $now->startOfMonth(), $now,
                $now->subMonthNoOverflow()->startOfMonth(), $now->subMonthNoOverflow(),
            ],
            MetricRange::QuarterToDate => [
                $now->startOfQuarter(), $now,
                $now->subQuarterNoOverflow()->startOfQuarter(), $now->subQuarterNoOverflow(),
            ],
            MetricRange::YearToDate => [
                $now->startOfYear(), $now,
                $now->subYearNoOverflow()->startOfYear(), $now->subYearNoOverflow(),
            ],
            null => [
                $now->subDays((int) $range), $now,
                $now->subDays((int) $range * 2), $now->subDays((int) $range),
            ],
        };
    }
}
