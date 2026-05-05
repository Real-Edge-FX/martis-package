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

When multiple dashboards are registered, every user picks how they surface in the panel chrome via the PreferencesMenu cog (v1.10.4+):

- **`tabs`** (default) — a single "Dashboard" entry in the sidebar plus a tab strip at the top of every dashboard view. Same as pre-v1.10.4.
- **`sidebar`** — every registered dashboard becomes its own sidebar entry under `DASHBOARDS`, using `Dashboard::name()` as the label and `/dashboards/{uriKey}` as the URL. The first dashboard doubles as the panel root link (`/`) so deep-link bookmarks and the sidebar stay in sync. The in-page tab strip is hidden in this mode.

Both surfaces share the same `/api/dashboards` payload and respect `canSee()` per dashboard. Persisted as `dashboardsLayout` on the user preferences row; defaults to `'tabs'` so existing installs see no behaviour change until a user opts in.

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

## Layout Type

`Dashboard::layoutType(): string` returns the identifier the frontend uses to pick a layout renderer for the cards collection. Defaults to the standard grid; override only when shipping a custom dashboard component:

```php
public function layoutType(): string
{
    return 'kanban'; // matches a key the frontend knows how to render
}
```

## Custom Component

`Dashboard::componentKey(string)` swaps the entire dashboard view for a custom React component. The component must be registered in `componentRegistry` and able to consume the standard dashboard payload (`cards`, `filters`, `meta`):

```php
SalesDashboard::make('Sales')->componentKey('custom-sales-dashboard');
```

Use `component(): ?string` to read the configured key back.

## Metadata

Pass arbitrary metadata to the frontend with `withMeta()`. The values surface verbatim in the dashboard payload's `meta` block, so custom layout types or dashboard components can read them via `meta.*`:

```php
SalesDashboard::make('Sales')->withMeta([
    'briefing' => 'Live every weekday at 09:00 WET.',
    'owner'    => 'sales-leadership@example.com',
]);
```

## Customising the breadcrumb (v1.10.3+)

By default the breadcrumb on `/martis/dashboards/{uriKey}` reads `Dashboard::name()` — the page heading and the deepest crumb stay in lock-step. Set a dedicated label when you want them to diverge (compact crumb + verbose heading, branded crumb + neutral heading, etc.):

```php
SalesDashboard::make('Quarterly sales briefing')
    ->withBreadcrumb('Sales');
```

The breadcrumb is the **only** thing that changes — `name()` still feeds the page heading, the sidebar entry, the `document.title`, and the menu shortcut from `MenuItem::dashboard()`.

For a translation-friendly variant, override `breadcrumb()` so the label re-resolves on every request and honours locale switches:

```php
public function breadcrumb(): ?string
{
    return (string) __('app.dashboards.sales.breadcrumb');
}
```

Return `null` to fall back to `name()`.

## Artisan Command

```bash
php artisan martis:dashboard SalesDashboard
```

## Cache Configuration

> **Martis extension** — global defaults in addition to per-metric caching.

Cache defaults live under the `cache` block of `config/martis.php`. Each subsystem (`metrics`, `dashboards`, `navigation`, `schema`) has its own `{enabled, ttl}` pair, plus a master kill-switch (`cache.enabled`):

```php
'cache' => [
    'enabled' => env('MARTIS_CACHE_ENABLED', true),

    'metrics' => [
        'enabled' => env('MARTIS_CACHE_METRICS_ENABLED', true),
        'ttl'     => env('MARTIS_CACHE_METRICS_TTL', env('MARTIS_CACHE_METRICS', 5)),
    ],
    'dashboards' => [
        'enabled' => env('MARTIS_CACHE_DASHBOARDS_ENABLED', true),
        'ttl'     => env('MARTIS_CACHE_DASHBOARDS_TTL', env('MARTIS_CACHE_DASHBOARDS', null)),
    ],
    // navigation / schema follow the same shape
],
```

`ttl` is in minutes; `null` disables caching for that subsystem. Individual metrics override the global value via `cacheFor()`. Runtime overrides set with `php artisan martis:cache:disable {type}` survive restarts and take precedence over both layers. See [metrics.md — Cache Configuration](metrics.md#cache-configuration-martis-extension) for the full block.

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
