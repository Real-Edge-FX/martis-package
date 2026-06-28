<?php

declare(strict_types=1);

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Martis\Metrics\TrendMetric;
use Martis\Metrics\TrendResult;

/*
 * DB-level coverage for TrendMetric::aggregateByPeriod().
 *
 * The original implementation used MySQL-only `DATE_FORMAT(...)` in a
 * raw select, which has no equivalent function on SQLite (the test
 * driver) or Postgres — so the entire trend DB aggregation path 500'd
 * on every non-MySQL connection and had ZERO passing DB coverage (the
 * unit tests only exercised hand-built TrendResult objects). v1.15.x
 * buckets in PHP with Carbon instead, which is correct on every driver
 * and needs no SQL date-format dialect. These tests run on SQLite and
 * therefore only pass once the driver-specific SQL is gone.
 */

class TrendAggTestOrder extends Model
{
    protected $table = 'trend_agg_test_orders';

    protected $fillable = ['amount', 'created_at'];

    protected $casts = ['created_at' => 'datetime'];
}

class TrendAggCountMetric extends TrendMetric
{
    public function calculate(Request $request): TrendResult
    {
        return $this->countByDays($request, TrendAggTestOrder::class);
    }
}

class TrendAggSumMetric extends TrendMetric
{
    public function calculate(Request $request): TrendResult
    {
        return $this->sumByDays($request, TrendAggTestOrder::class, 'amount');
    }
}

class TrendAggAvgMetric extends TrendMetric
{
    public function calculate(Request $request): TrendResult
    {
        return $this->averageByDays($request, TrendAggTestOrder::class, 'amount');
    }
}

class TrendAggWeekCountMetric extends TrendMetric
{
    public function calculate(Request $request): TrendResult
    {
        return $this->countByWeeks($request, TrendAggTestOrder::class);
    }
}

beforeEach(function () {
    // Freeze "now" so the date-range window and bucket keys are
    // deterministic regardless of when the suite runs.
    Carbon::setTestNow('2026-06-15 12:00:00');

    Schema::dropIfExists('trend_agg_test_orders');
    Schema::create('trend_agg_test_orders', function ($table) {
        $table->id();
        $table->decimal('amount', 10, 2)->default(0);
        $table->timestamps();
    });

    // In-range rows (default range = 30 days back from 2026-06-15 ->
    // window opens 2026-05-16):
    TrendAggTestOrder::create(['amount' => 10, 'created_at' => '2026-06-14 09:00:00']);
    TrendAggTestOrder::create(['amount' => 20, 'created_at' => '2026-06-14 18:00:00']);
    TrendAggTestOrder::create(['amount' => 5, 'created_at' => '2026-06-15 08:00:00']);
    TrendAggTestOrder::create(['amount' => 100, 'created_at' => '2026-06-10 12:00:00']);
    // Out-of-range row (before the 30-day window) — must be excluded:
    TrendAggTestOrder::create(['amount' => 999, 'created_at' => '2026-04-01 12:00:00']);
});

afterEach(function () {
    Carbon::setTestNow();
    Schema::dropIfExists('trend_agg_test_orders');
});

it('countByDays aggregates on SQLite and excludes out-of-range rows', function () {
    $result = TrendAggCountMetric::make('Orders')->calculate(Request::create('/'))->toArray();

    // 4 in-range rows, the 2026-04-01 row excluded.
    expect(array_sum($result['values']))->toBe(4.0);
});

it('sumByDays sums the value column on SQLite', function () {
    $result = TrendAggSumMetric::make('Revenue')->calculate(Request::create('/'))->toArray();

    // 10 + 20 + 5 + 100 (the 999 row is out of range).
    expect(array_sum($result['values']))->toBe(135.0);
});

it('averageByDays averages per populated day without dividing by zero', function () {
    $result = TrendAggAvgMetric::make('Avg amount')->calculate(Request::create('/'))->toArray();

    // 2026-06-14 has two rows -> avg 15; the bucket for that day must be 15.
    expect($result['values'])->toContain(15.0);
});

it('countByWeeks buckets by ISO week on SQLite (the cross-driver crux)', function () {
    $result = TrendAggWeekCountMetric::make('Weekly orders')->calculate(Request::create('/'))->toArray();

    // The week window is 30 WEEKS back (not 30 days), so it opens
    // ~2025-11 and includes ALL five seeded rows (the 2026-04-01 row is
    // within 30 weeks). The assertion that matters cross-driver: every
    // row is counted exactly once and NONE are dropped by a SQL-vs-PHP
    // ISO-week key mismatch (the exact failure mode SQLite's missing
    // ISO-week token would cause).
    expect(array_sum($result['values']))->toBe(5.0);
});
