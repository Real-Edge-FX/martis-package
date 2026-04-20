# Metrics

Metrics compute and display analytical data on dashboards and resource index pages. Martis provides four built-in metric types.

## Metric Types

### Value Metric

Displays a single number with optional period comparison.

```php
use App\Models\User;
use Illuminate\Http\Request;
use Martis\Metrics\ValueMetric;
use Martis\Metrics\ValueResult;

class TotalUsers extends ValueMetric
{
    public function calculate(Request $request): ValueResult
    {
        return $this->count($request, User::class)
            ->suffix(' users');
    }
}
```

**Query helpers:** `count()`, `sum()`, `average()`, `max()`, `min()`.

**Result formatting:**
```php
$this->result(150)->prefix('$')->suffix(' revenue')
$this->count($request, User::class)->currency('€')
$this->sum($request, Order::class, 'total')->dollars()
```

### Trend Metric

Displays time-series data as a line chart.

```php
use App\Models\User;
use Illuminate\Http\Request;
use Martis\Metrics\TrendMetric;
use Martis\Metrics\TrendResult;

class UsersPerDay extends TrendMetric
{
    public function calculate(Request $request): TrendResult
    {
        return $this->countByDays($request, User::class)
            ->showLatestValue();
    }
}
```

**Query helpers:** `countByDays()`, `countByWeeks()`, `countByMonths()`, `sumByDays()`, `sumByWeeks()`, `sumByMonths()`, `averageByDays()`, `averageByMonths()`.

### Partition Metric

Displays grouped data as a doughnut chart.

```php
use App\Models\User;
use Illuminate\Http\Request;
use Martis\Metrics\PartitionMetric;
use Martis\Metrics\PartitionResult;

class UsersByRole extends PartitionMetric
{
    public function calculate(Request $request): PartitionResult
    {
        return $this->count($request, User::class, 'role')
            ->colors(['#22c55e', '#ef4444', '#3b82f6'])
            ->label(fn ($l) => strtoupper($l));
    }
}
```

**Query helpers:** `count()`, `sum()`, `average()`.

### Progress Metric

Displays progress toward a target as a progress bar.

```php
use App\Models\User;
use Illuminate\Http\Request;
use Martis\Metrics\ProgressMetric;
use Martis\Metrics\ProgressResult;

class MonthlySignups extends ProgressMetric
{
    public function calculate(Request $request): ProgressResult
    {
        return $this->count(
            $request,
            User::class,
            fn ($q) => $q->where('created_at', '>=', now()->startOfMonth()),
            target: 100,
        );
    }
}
```

Use `->avoid()` when the goal is to minimize rather than maximize.

## Ranges

Metrics support time range selection. Override `ranges()` to customize:

```php
public function ranges(): array
{
    return [
        30 => '30 Days',
        60 => '60 Days',
        365 => '1 Year',
        'TODAY' => 'Today',
        'MTD' => 'Month To Date',
        'QTD' => 'Quarter To Date',
        'YTD' => 'Year To Date',
    ];
}
```

## Card Width

Cards use a **12-column grid**. Default width is 4 (one-third).

```php
TotalUsers::make('Total Users')->width(4)     // 1/3
UsersPerDay::make('Trend')->width(8)          // 2/3
UsersByRole::make('Roles')->width(6)          // 1/2
Overview::make('Overview')->width('full')      // full width
```

Fraction strings are auto-converted: `'1/3'` → 4, `'1/2'` → 6, `'2/3'` → 8, `'full'` → 12.

> **Martis extension:** Responsive breakpoints with `widthMd()` and `widthLg()`:
```php
TotalUsers::make('Total Users')
    ->width(12)       // mobile: full width
    ->widthMd(6)      // tablet: half
    ->widthLg(4)      // desktop: one-third
```

## Caching

Override `cacheFor()` to cache metric results:

```php
public function cacheFor(): ?\DateTimeInterface
{
    return now()->addMinutes(5);
}
```

## Authorization

Use `canSee()` to control metric visibility:

```php
TotalUsers::make('Total Users')
    ->canSee(fn ($request) => $request->user()->isAdmin())
```

## Card Icons (Martis Extension)

Add a Phosphor icon to the card header:

```php
TotalUsers::make('Total Users')->icon('users')
Revenue::make('Revenue')->icon('currency-dollar')
TasksTrend::make('Tasks')->icon('chart-line-up')
```

The icon renders next to the card title and inherits the card style color.

## Card Styles (Martis Extension)

Apply a colored accent (left border) to highlight card importance:

```php
use Martis\Enums\CardStyle;

TotalUsers::make('Total Users')->style(CardStyle::Info)        // blue
Revenue::make('Revenue')->style(CardStyle::Success)            // green
OverdueInvoices::make('Overdue')->style(CardStyle::Danger)     // red
PendingTasks::make('Pending')->style(CardStyle::Warning)       // yellow
```

Available styles: `Default`, `Success`, `Warning`, `Danger`, `Info`.

## Card Height (Martis Extension)

Set a minimum height to align cards in a row:

```php
UsersByRole::make('By Role')->height(300)  // 300px minimum height
```

## Cache Configuration (Martis Extension)

### Per-metric caching

Override `cacheFor()` on any metric class:

```php
public function cacheFor(): ?\DateTimeInterface
{
    return now()->addMinutes(5);
}
```

### Global cache defaults

Set defaults in `config/martis.php` — individual metrics override these:

```php
'cache' => [
    'metrics'    => env('MARTIS_CACHE_METRICS', 5),     // minutes, null to disable
    'dashboards' => env('MARTIS_CACHE_DASHBOARDS', null),
    'navigation' => env('MARTIS_CACHE_NAVIGATION', 1),
    'schema'     => env('MARTIS_CACHE_SCHEMA', null),
],
```

## Auto-Refresh (Martis Extension)

Martis supports automatic polling.

```php
ActiveUsers::make('Active Now')->refreshEvery(30)  // refresh every 30 seconds
```

Cards with polling show a "LIVE" indicator.

## Artisan Commands

```bash
php artisan martis:value TotalUsers
php artisan martis:trend UsersPerDay
php artisan martis:partition UsersByRole
php artisan martis:progress MonthlyGoal
```

## API

### Compute a dashboard metric

```
GET /api/dashboards/{dashboard}/cards/{card}?range=30
```

### Compute a resource metric

```
GET /api/resources/{resource}/cards/{card}?range=30
```
