<?php

use Illuminate\Http\Request;
use Martis\Enums\MetricType;
use Martis\Metrics\PartitionMetric;
use Martis\Metrics\PartitionResult;
use Martis\Metrics\ProgressMetric;
use Martis\Metrics\ProgressResult;
use Martis\Metrics\TrendMetric;
use Martis\Metrics\TrendResult;
use Martis\Metrics\ValueMetric;
use Martis\Metrics\ValueResult;

// ---------------------------------------------------------------------------
// Test concrete metrics
// ---------------------------------------------------------------------------

class TestTotalUsersMetric extends ValueMetric
{
    public function calculate(Request $request): ValueResult
    {
        return $this->result(150)->previous(120)->prefix('$');
    }
}

class TestUsersPerDayMetric extends TrendMetric
{
    public function calculate(Request $request): TrendResult
    {
        return $this->result(
            ['Mon', 'Tue', 'Wed', 'Thu', 'Fri'],
            [10, 15, 8, 22, 17],
        )->showLatestValue()->prefix('Users: ');
    }
}

class TestUsersByRoleMetric extends PartitionMetric
{
    public function calculate(Request $request): PartitionResult
    {
        return $this->result([
            'Admin' => 5,
            'Editor' => 15,
            'Viewer' => 80,
        ])->colors(['#FF5733', '#33FF57', '#3357FF']);
    }
}

class TestMonthlyGoalMetric extends ProgressMetric
{
    public function calculate(Request $request): ProgressResult
    {
        return $this->result(75, 100)->dollars();
    }
}

// ---------------------------------------------------------------------------
// ValueMetric
// ---------------------------------------------------------------------------

it('ValueMetric has correct metricType', function () {
    $metric = TestTotalUsersMetric::make('Total Users');
    expect($metric->metricType())->toBe(MetricType::Value);
});

it('ValueMetric resolves result with prefix and previous', function () {
    $metric = TestTotalUsersMetric::make('Total Users');
    $result = $metric->resolve(Request::create('/'));

    expect($result['value'])->toBe(150)
        ->and($result['previous'])->toBe(120)
        ->and($result['prefix'])->toBe('$')
        ->and($result['change'])->toBe(25.0);
});

it('ValueResult supports currency shortcuts', function () {
    $result = (new ValueResult(100))->euros()->suffix(' revenue');

    $arr = $result->toArray();
    expect($arr['prefix'])->toBe('€')
        ->and($arr['suffix'])->toBe(' revenue');
});

it('ValueResult calculates change percentage', function () {
    $result = (new ValueResult(150))->previous(100);
    $arr = $result->toArray();

    expect($arr['change'])->toBe(50.0);
});

it('ValueResult handles zero previous without division error', function () {
    $result = (new ValueResult(50))->previous(0);
    $arr = $result->toArray();

    expect($arr)->not->toHaveKey('change');
});

it('ValueResult computes change from a negative previous', function () {
    // Regression: the guard was `previous > 0`, which silently dropped
    // `change` for any negative baseline (a loss, a deficit, a negative
    // balance). Division by a negative number is mathematically valid;
    // only zero must be excluded. -500 -> +200 is a +140% swing.
    $result = (new ValueResult(200))->previous(-500);
    $arr = $result->toArray();

    expect($arr)->toHaveKey('change')
        ->and($arr['change'])->toBe(-140.0);
});

// ---------------------------------------------------------------------------
// TrendMetric
// ---------------------------------------------------------------------------

it('TrendMetric has correct metricType', function () {
    $metric = TestUsersPerDayMetric::make('Users Per Day');
    expect($metric->metricType())->toBe(MetricType::Trend);
});

it('TrendMetric resolves with labels, values, and latestValue', function () {
    $metric = TestUsersPerDayMetric::make('Users Per Day');
    $result = $metric->resolve(Request::create('/'));

    expect($result['labels'])->toBe(['Mon', 'Tue', 'Wed', 'Thu', 'Fri'])
        ->and($result['values'])->toBe([10, 15, 8, 22, 17])
        ->and($result['latestValue'])->toBe(17)
        ->and($result['prefix'])->toBe('Users: ');
});

it('TrendResult supports showSumValue', function () {
    $result = (new TrendResult(['A', 'B'], [10, 20]))->showSumValue();
    $arr = $result->toArray();

    expect($arr['sumValue'])->toBe(30);
});

it('TrendResult omits change entirely when the delta is null (sparkline, zero-first series)', function () {
    // Regression: a sparkline series whose first bucket is 0 (common for
    // brand-new data) made computeDelta() return null, and toArray()
    // emitted `change: null`. The frontend guard was `change !== undefined`,
    // which is true for a JSON null, so the card rendered the literal
    // string "null%". The contract: when there is no meaningful delta,
    // omit the key so the frontend's `!= null` guard suppresses the row.
    $result = (new TrendResult(['Mon', 'Tue', 'Wed'], [0, 5, 9]))->sparkline();
    $arr = $result->toArray();

    expect($arr['sparkline'])->toBeTrue()
        ->and($arr)->not->toHaveKey('change');
});

it('TrendResult keeps change when the delta is a real number', function () {
    $result = (new TrendResult(['Mon', 'Tue', 'Wed'], [10, 15, 20]))->sparkline();
    $arr = $result->toArray();

    expect($arr)->toHaveKey('change')
        ->and($arr['change'])->toBe(100.0);
});

// ---------------------------------------------------------------------------
// PartitionMetric
// ---------------------------------------------------------------------------

it('PartitionMetric has correct metricType', function () {
    $metric = TestUsersByRoleMetric::make('Users By Role');
    expect($metric->metricType())->toBe(MetricType::Partition);
});

it('PartitionMetric resolves with labels, values, and colors', function () {
    $metric = TestUsersByRoleMetric::make('Users By Role');
    $result = $metric->resolve(Request::create('/'));

    expect($result['labels'])->toBe(['Admin', 'Editor', 'Viewer'])
        ->and($result['values'])->toBe([5, 15, 80])
        ->and($result['colors'])->toBe(['#FF5733', '#33FF57', '#3357FF']);
});

it('PartitionResult supports label callback', function () {
    $result = (new PartitionResult(['admin' => 5, 'viewer' => 10]))
        ->label(fn (string $l) => strtoupper($l));

    $arr = $result->toArray();
    expect($arr['labels'])->toBe(['ADMIN', 'VIEWER']);
});

it('PartitionMetric has empty ranges', function () {
    $metric = TestUsersByRoleMetric::make('Test');
    expect($metric->ranges())->toBe([]);
});

// ---------------------------------------------------------------------------
// ProgressMetric
// ---------------------------------------------------------------------------

it('ProgressMetric has correct metricType', function () {
    $metric = TestMonthlyGoalMetric::make('Monthly Goal');
    expect($metric->metricType())->toBe(MetricType::Progress);
});

it('ProgressMetric resolves with current, target, percentage', function () {
    $metric = TestMonthlyGoalMetric::make('Monthly Goal');
    $result = $metric->resolve(Request::create('/'));

    expect($result['current'])->toBe(75)
        ->and($result['target'])->toBe(100)
        ->and($result['percentage'])->toBe(75.0)
        ->and($result['prefix'])->toBe('$')
        ->and($result['avoid'])->toBeFalse();
});

it('ProgressResult supports avoid mode', function () {
    $result = (new ProgressResult(30, 100))->avoid();
    $arr = $result->toArray();

    expect($arr['avoid'])->toBeTrue()
        ->and($arr['percentage'])->toBe(30.0);
});

// ---------------------------------------------------------------------------
// Metric base — width, canSee, refreshEvery, toArray
// ---------------------------------------------------------------------------

it('Metric default width is 4 (one-third)', function () {
    $metric = TestTotalUsersMetric::make('Test');
    expect($metric->toArray()['width'])->toBe(4);
});

it('Metric width accepts integer grid values', function () {
    $metric = TestTotalUsersMetric::make('Test')->width(6);
    expect($metric->toArray()['width'])->toBe(6);
});

it('Metric width converts fraction strings', function () {
    expect(TestTotalUsersMetric::make('T')->width('1/3')->toArray()['width'])->toBe(4);
    expect(TestTotalUsersMetric::make('T')->width('1/2')->toArray()['width'])->toBe(6);
    expect(TestTotalUsersMetric::make('T')->width('2/3')->toArray()['width'])->toBe(8);
    expect(TestTotalUsersMetric::make('T')->width('full')->toArray()['width'])->toBe(12);
});

it('Metric supports responsive widths (Martis extension)', function () {
    $metric = TestTotalUsersMetric::make('Test')->width(12)->widthMd(6)->widthLg(4);
    $arr = $metric->toArray();

    expect($arr['width'])->toBe(12)
        ->and($arr['widthMd'])->toBe(6)
        ->and($arr['widthLg'])->toBe(4);
});

it('Metric canSee hides when false', function () {
    $metric = TestTotalUsersMetric::make('Test')->canSee(fn () => false);
    expect($metric->authorizedToSee(Request::create('/')))->toBeFalse();
});

it('Metric canSee shows when true', function () {
    $metric = TestTotalUsersMetric::make('Test')->canSee(fn () => true);
    expect($metric->authorizedToSee(Request::create('/')))->toBeTrue();
});

it('Metric visible by default', function () {
    $metric = TestTotalUsersMetric::make('Test');
    expect($metric->authorizedToSee(Request::create('/')))->toBeTrue();
});

it('Metric refreshEvery sets polling interval (Martis extension)', function () {
    $metric = TestTotalUsersMetric::make('Test')->refreshEvery(30);
    expect($metric->toArray()['refreshEvery'])->toBe(30);
});

it('Metric refreshEvery enforces minimum 5 seconds', function () {
    $metric = TestTotalUsersMetric::make('Test')->refreshEvery(2);
    expect($metric->toArray()['refreshEvery'])->toBe(5);
});

it('Metric onlyOnDetail flag', function () {
    $metric = TestTotalUsersMetric::make('Test')->onlyOnDetail();
    expect($metric->toArray()['onlyOnDetail'])->toBeTrue();
});

it('Metric default ranges include standard options', function () {
    $metric = TestTotalUsersMetric::make('Test');
    $ranges = $metric->ranges();

    expect($ranges)->toHaveKey(30)
        ->and($ranges)->toHaveKey('TODAY')
        ->and($ranges)->toHaveKey('MTD')
        ->and($ranges)->toHaveKey('YTD');
});

it('Metric toArray includes all schema keys', function () {
    $metric = TestTotalUsersMetric::make('Total Users');
    $arr = $metric->toArray();

    expect($arr)->toHaveKeys([
        'type', 'metricType', 'name', 'uriKey', 'component',
        'width', 'widthMd', 'widthLg', 'ranges', 'refreshEvery',
        'onlyOnDetail', 'height', 'style', 'meta',
    ])
        ->and($arr['type'])->toBe('metric')
        ->and($arr['metricType'])->toBe('value')
        ->and($arr['name'])->toBe('Total Users')
        ->and($arr['uriKey'])->toBe('total-users');
});

// ---------------------------------------------------------------------------
// help() — tooltip text on metric cards (v1.1)
// ---------------------------------------------------------------------------

it('Metric help() defaults to null (no tooltip rendered)', function () {
    $metric = TestTotalUsersMetric::make('Test');
    expect($metric->helpText())->toBeNull();
    expect($metric->toArray()['help'])->toBeNull();
});

it('Metric help(string) attaches tooltip text and serializes it', function () {
    $metric = TestTotalUsersMetric::make('Test')
        ->help('Counts all users created in the selected range, including soft-deleted ones.');
    expect($metric->helpText())->toBe('Counts all users created in the selected range, including soft-deleted ones.');
    expect($metric->toArray()['help'])->toBe('Counts all users created in the selected range, including soft-deleted ones.');
});

it('Metric help(null) clears a previously set tooltip', function () {
    $metric = TestTotalUsersMetric::make('Test')->help('temp');
    expect($metric->helpText())->toBe('temp');
    $metric->help(null);
    expect($metric->helpText())->toBeNull();
    expect($metric->toArray()['help'])->toBeNull();
});

it('Metric help() returns the same instance for chaining', function () {
    $metric = TestTotalUsersMetric::make('Test');
    expect($metric->help('A'))->toBe($metric);
});

// ---------------------------------------------------------------------------
// TrendResult — latestValue edge cases
// ---------------------------------------------------------------------------

it('TrendResult showLatestValue omits latestValue key for empty series', function () {
    $result = (new TrendResult([], []))->showLatestValue();
    $arr = $result->toArray();

    expect($arr)->not->toHaveKey('latestValue');
});

it('TrendResult showLatestValue preserves a legitimate last value of zero', function () {
    $result = (new TrendResult(['A', 'B', 'C'], [5, 3, 0]))->showLatestValue();
    $arr = $result->toArray();

    expect($arr)->toHaveKey('latestValue')
        ->and($arr['latestValue'])->toBe(0);
});

it('TrendResult showLatestValue preserves a legitimate last value of 0.0', function () {
    $result = (new TrendResult(['A', 'B'], [1.5, 0.0]))->showLatestValue();
    $arr = $result->toArray();

    expect($arr)->toHaveKey('latestValue')
        ->and($arr['latestValue'])->toBe(0.0);
});

// ---------------------------------------------------------------------------
// ProgressResult — constructor guard for negative target
// ---------------------------------------------------------------------------

it('ProgressResult throws InvalidArgumentException when target is negative', function () {
    expect(fn () => new ProgressResult(50, -1))
        ->toThrow(InvalidArgumentException::class, 'ProgressResult target must be >= 0.');
});

it('ProgressResult accepts zero target without throwing', function () {
    $arr = (new ProgressResult(0, 0))->toArray();

    expect($arr['percentage'])->toBe(0);
});
