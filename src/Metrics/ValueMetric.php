<?php

namespace Martis\Metrics;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * A metric that displays a single value with optional period comparison.
 *
 * Nova v5 parity: count, sum, average, max, min with ranges and formatting.
 *
 * @phpstan-consistent-constructor
 */
abstract class ValueMetric extends Metric
{
    public function metricType(): string
    {
        return 'value';
    }

    // -------------------------------------------------------------------------
    // Query helpers — Nova v5 parity
    // -------------------------------------------------------------------------

    /**
     * Count records for the current and previous periods.
     *
     * @param  class-string<Model>  $model
     * @param  string|null  $dateColumn
     */
    protected function count(Request $request, string $model, ?string $dateColumn = null): ValueResult
    {
        return $this->aggregate($request, $model, 'count', null, $dateColumn);
    }

    /**
     * Sum a column for the current and previous periods.
     *
     * @param  class-string<Model>  $model
     */
    protected function sum(Request $request, string $model, string $column, ?string $dateColumn = null): ValueResult
    {
        return $this->aggregate($request, $model, 'sum', $column, $dateColumn);
    }

    /**
     * Average a column for the current and previous periods.
     *
     * @param  class-string<Model>  $model
     */
    protected function average(Request $request, string $model, string $column, ?string $dateColumn = null): ValueResult
    {
        return $this->aggregate($request, $model, 'avg', $column, $dateColumn);
    }

    /**
     * Max of a column for the current and previous periods.
     *
     * @param  class-string<Model>  $model
     */
    protected function max(Request $request, string $model, string $column, ?string $dateColumn = null): ValueResult
    {
        return $this->aggregate($request, $model, 'max', $column, $dateColumn);
    }

    /**
     * Min of a column for the current and previous periods.
     *
     * @param  class-string<Model>  $model
     */
    protected function min(Request $request, string $model, string $column, ?string $dateColumn = null): ValueResult
    {
        return $this->aggregate($request, $model, 'min', $column, $dateColumn);
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
    protected function aggregate(Request $request, string $model, string $function, ?string $column, ?string $dateColumn): ValueResult
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

        $currentValue = $function === 'count'
            ? $currentQuery->count()
            : $currentQuery->{$function}($column);

        $previousValue = $function === 'count'
            ? $previousQuery->count()
            : $previousQuery->{$function}($column);

        return (new ValueResult((float) ($currentValue ?? 0)))
            ->previous((float) ($previousValue ?? 0));
    }

    /**
     * Calculate date ranges for current and previous periods.
     *
     * @return array{CarbonImmutable, CarbonImmutable, CarbonImmutable, CarbonImmutable}
     */
    protected function calculateDateRange(string|int $range): array
    {
        $now = CarbonImmutable::now();

        return match ((string) $range) {
            'TODAY' => [
                $now->startOfDay(), $now,
                $now->subDay()->startOfDay(), $now->subDay()->endOfDay(),
            ],
            'MTD' => [
                $now->startOfMonth(), $now,
                $now->subMonthNoOverflow()->startOfMonth(), $now->subMonthNoOverflow(),
            ],
            'QTD' => [
                $now->startOfQuarter(), $now,
                $now->subQuarterNoOverflow()->startOfQuarter(), $now->subQuarterNoOverflow(),
            ],
            'YTD' => [
                $now->startOfYear(), $now,
                $now->subYearNoOverflow()->startOfYear(), $now->subYearNoOverflow(),
            ],
            default => [
                $now->subDays((int) $range), $now,
                $now->subDays((int) $range * 2), $now->subDays((int) $range),
            ],
        };
    }
}
