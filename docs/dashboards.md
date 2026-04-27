# Dashboards

Dashboards are containers for metric cards. Martis supports multiple dashboards, each with its own set of cards, filters, and authorization.

> See [Metrics](metrics.md) for the metric types (`ValueMetric`, `TrendMetric`, `PartitionMetric`, `ProgressMetric`, `ActivityFeedMetric`, `EndpointTableMetric`) you mount on a dashboard.

## Creating a Dashboard

```php
use Illuminate\Http\Request;
use Martis\Dashboards\Dashboard;

class SalesDashboard extends Dashboard
{
    public function __construct()
    {
        parent::__construct('Sales');
    }

    public function cards(Request $request): array
    {
        return [
            TotalRevenue::make('Revenue')->width(4),
            SalesPerDay::make('Daily Sales')->width(8),
            SalesByRegion::make('By Region')->width(6),
            MonthlyTarget::make('Target')->width(6),
        ];
    }
}
```

## Registering Dashboards

Register dashboards in `app/Providers/MartisServiceProvider.php` (published by `martis:install`):

```php
// app/Providers/MartisServiceProvider.php
protected function registerDashboards(): void
{
    Martis::dashboards([
        \App\Martis\Dashboards\MainDashboard::class,
        \App\Martis\Dashboards\SalesDashboard::class,
    ]);
}
```

When multiple dashboards are registered, the frontend shows tabs to switch between them.

## Refresh Button

Override `showRefreshButton()` to add a manual refresh button:

```php
public function showRefreshButton(): bool
{
    return true;
}
```

## Authorization

Use `canSee()` to control dashboard visibility:

```php
Martis::dashboards([
    MainDashboard::make('Main'),
    SalesDashboard::make('Sales')->canSee(fn ($r) => $r->user()->isAdmin()),
]);
```

## Dashboard Filters (Martis Extension)

Martis allows declarative filters on dashboards that affect all cards. This reuses the same Filter system from resources:

```php
use Martis\Filters\DateRangeFilter;
use Martis\Filters\SelectFilter;

class SalesDashboard extends Dashboard
{
    public function filters(Request $request): array
    {
        return [
            RegionFilter::make('Region')->span(4),
            DateRangeFilter::make('Period')->column('created_at')->span(8),
        ];
    }
}
```

Filters support `->span()` for layout control (1-12 column grid). Default spans: select = 3, date-range = 6.

Filter values are passed to each card's compute endpoint and automatically applied to all built-in query helpers (count, sum, average, etc.).

## Artisan Command

```bash
php artisan martis:dashboard SalesDashboard
```

## Cache Configuration

> **Martis extension** — global defaults in addition to per-metric caching.

Set global cache defaults in `config/martis.php`:

```php
'cache' => [
    'metrics'    => env('MARTIS_CACHE_METRICS', 5),     // minutes
    'dashboards' => env('MARTIS_CACHE_DASHBOARDS', null),
],
```

Individual metrics can override with `cacheFor()`. The global config acts as a fallback.

## Default Dashboard

`Martis\Dashboards\DefaultDashboard` is the built-in landing dashboard. Register it alongside your custom dashboards to expose the default surface as a selectable tab:

```php
Martis::dashboards([
    \Martis\Dashboards\DefaultDashboard::class,
    MyCustomDashboard::class,
]);
```

The default dashboard renders, top to bottom:

- **Welcome hero card** — animated gradient surface with the configured heading, description, and the package version resolved dynamically from Composer's installed tag. Override the copy by publishing `lang/vendor/martis/*/resources.php` and editing `welcome_card_heading` / `welcome_card_description`. Hide the card via `config('martis.dashboard.showWelcomeCard', false)` or the `MARTIS_DASHBOARD_SHOW_WELCOME_CARD=false` env flag.
- Resource count stats (total, groups, active).
- Resource shortcut cards derived from the registered navigation.

When **no** dashboards are registered at all, the same default view renders as a fallback so existing installations stay functional.

### Density-aware surfaces

`StatCard`, `MetricCard`, the resource shortcut cards, and the welcome hero all tighten their internal padding under `html[data-density="dense"]`. The mapping is CSS-only — the hook is the class prefix each surface already carries (`martis-stat-card`, `martis-metric-card-head` / `-body`, `martis-resource-card`, `mwc-root`) — so consumer-built metric cards that follow the same class convention pick up the density response automatically.

## API

### List dashboards

```
GET /api/dashboards
```

### Get dashboard with cards and filters

```
GET /api/dashboards/{dashboard}
```

### Compute a metric

```
GET /api/dashboards/{dashboard}/cards/{card}?range=30&filters={...}
```
