# Metrics

Metrics compute and display analytical data on dashboards and resource index pages. Martis provides six built-in metric types: `Value`, `Trend`, `Partition`, `Progress`, `ActivityFeed`, and `EndpointTable`.

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

### Activity Feed Metric (Martis Extension)

Renders a chronological stream of recent events with a coloured Phosphor avatar tile, an actor / verb / target line, and a mono timestamp. Useful for "Recent activity" or "Latest deployments" cards on dashboards.

```php
use Illuminate\Http\Request;
use Martis\Metrics\ActivityFeedMetric;
use Martis\Metrics\ActivityFeedResult;

class RecentDeploys extends ActivityFeedMetric
{
    public function calculate(Request $request): ActivityFeedResult
    {
        return $this->result()
            ->add(
                actor: 'Ana Pereira',
                verb: 'deployed',
                time: '2m ago',
                target: 'api-core@v2.4.1',
                icon: 'rocket-launch',
                color: 'var(--martis-chart-2)',
            )
            ->add(
                actor: 'João Marques',
                verb: 'rotated key',
                time: '14m ago',
                target: 'production-api',
                icon: 'key',
                color: 'var(--martis-chart-1)',
            );
    }
}
```

`ActivityFeedResult::add()` parameters:

| Parameter | Required | Description |
|-----------|----------|-------------|
| `actor`   | yes      | Bold name at the start of the line. |
| `verb`    | yes      | Muted action description after the actor. |
| `time`    | yes      | Relative timestamp shown on a second mono line ("2m ago", "yesterday"). |
| `target`  | no       | Identifier rendered in mono (commit sha, route, key id). |
| `icon`    | no       | Phosphor icon name for the leading avatar tile. |
| `color`   | no       | CSS colour or token for the tile background (defaults to `--martis-accent`). |

Bulk-set entries via `$result->items([...])` if the data already comes back from a query in the right shape.

Generator: `php artisan martis:activity-feed RecentDeploys`.

### Endpoint Table Metric (Martis Extension)

Renders a compact HTTP route table with coloured method chips (`GET` / `POST` / `PUT` / `PATCH` / `DELETE`), mono numeric columns (req/min, P95 latency, error %), and a thin share-of-traffic bar. Drops cleanly into a `card-span-3` slot on the dashboard.

```php
use Illuminate\Http\Request;
use Martis\Metrics\EndpointTableMetric;
use Martis\Metrics\EndpointTableResult;

class TopEndpoints extends EndpointTableMetric
{
    public function calculate(Request $request): EndpointTableResult
    {
        return $this->result()
            ->errorWarnThreshold(0.2)
            ->add(method: 'GET', path: '/v1/resources', rpm: 482, latencyMs: 42, errorRate: 0.02, share: 28)
            ->add(method: 'POST', path: '/v1/events', rpm: 312, latencyMs: 88, errorRate: 0.12, share: 18)
            ->add(method: 'PATCH', path: '/v1/deployments/:id', rpm: 188, latencyMs: 126, errorRate: 0.18, share: 11);
    }
}
```

`EndpointTableResult::add()` parameters:

| Parameter | Required | Description |
|-----------|----------|-------------|
| `method`     | yes | HTTP verb. Drives the coloured chip in the first column. |
| `path`       | yes | Route path (rendered in mono). |
| `rpm`        | no  | Requests per minute. |
| `latencyMs`  | no  | P95 latency in milliseconds. |
| `errorRate`  | no  | 0..1. Highlights the cell amber when above `errorWarnThreshold` (default `0.2`). |
| `share`      | no  | 0..100. Renders the thin share bar. Omit on every row to hide the column entirely. |

Bulk-set via `$result->rows([...])`. Generator: `php artisan martis:endpoint-table TopEndpoints`.

### Sparkline mode on Trend metrics

`TrendResult` accepts a `sparkline` flag. When set, the `MetricCard` renders an inline SVG sparkline + delta pill instead of the full Chart.js panel. Pair with the `.martis-dash-kpis` row layout to fit four trend metrics across the top of a dashboard:

```php
class WeeklyRevenue extends TrendMetric
{
    public function calculate(Request $request): TrendResult
    {
        return $this->countByDays($request, Order::class)
            ->sparkline()                // ← compact mode
            ->showLatestValue()
            ->prefix('€');
    }
}
```

The `<Sparkline>` React component used internally is also exported (`@/components/metrics`) for use inside custom cards or framed components.

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

---

## MetricResult — base class

Every metric type returns a result object that subclasses `Martis\Metrics\MetricResult`. The base class has a single contract method:

```php
abstract public function toArray(): array;
```

Concrete subclasses (`ValueResult`, `TrendResult`, `PartitionResult`, `ProgressResult`, `ActivityFeedResult`, `EndpointTableResult`) implement this to serialise their specific shape into the API payload the React component consumes. Most consumers never touch the base class directly — you build results via the helpers on the metric class (`$this->result($value)`, `$this->trend($values)`, etc.) and let the metric handle serialisation.

Subclass `MetricResult` directly only if you're shipping a brand-new metric kind. Document the result shape in your own metric's docblock so consumers can read the payload without diving into source.
