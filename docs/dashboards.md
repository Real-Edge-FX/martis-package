# Dashboards

Dashboards are containers for metric cards. Martis supports multiple dashboards, each with its own set of cards, filters, and authorization.

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

Register dashboards in your `AppServiceProvider`:

```php
use Martis\Facades\Martis;

public function boot(): void
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

> Nova 5 does not support dashboard-level filters.

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

> **Martis extension** — Nova only supports per-metric caching.

Set global cache defaults in `config/martis.php`:

```php
'cache' => [
    'metrics'    => env('MARTIS_CACHE_METRICS', 5),     // minutes
    'dashboards' => env('MARTIS_CACHE_DASHBOARDS', null),
],
```

Individual metrics can override with `cacheFor()`. The global config acts as a fallback.

## Fallback Dashboard

When no dashboards are registered, Martis displays the default landing page with:
- Welcome greeting
- Resource count stats
- Resource shortcut cards

This ensures backward compatibility with existing installations.

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
