# Filters

Filters allow users to narrow down resource index results by applying criteria. They appear as a collapsible panel on the resource index page with active filter pills for at-a-glance visibility.

Martis ships four built-in filter types and supports custom filters for advanced use cases.

## Defining Filters

Register filters on a resource by overriding the `filters()` method:

```php
use Illuminate\Http\Request;

public function filters(Request $request): array
{
    return [
        StatusFilter::make('Status'),
        RoleFilter::make('Role'),
        DateFilter::make('Created After')->column('created_at')->operator('>='),
    ];
}
```

Filters are applied to the index query in the order they are defined. When multiple filters are active, they combine with `AND` logic.

## Available Filter Types

### SelectFilter

A dropdown filter that lets users pick a single value from a list.

```php
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Martis\Filters\SelectFilter;

class StatusFilter extends SelectFilter
{
    public function options(Request $request): array
    {
        return [
            'Active'   => 'active',
            'Inactive' => 'inactive',
            'Pending'  => 'pending',
        ];
    }

    public function apply(Request $request, Builder $query, mixed $value): Builder
    {
        return $query->where('status', $value);
    }
}
```

**Options format:** keys are display labels, values are passed to `apply()`.

**Grouped options:** organize large option lists into visual groups:

```php
public function options(Request $request): array
{
    return [
        'Numbers' => [
            'Odd'  => 'odd',
            'Even' => 'even',
        ],
        'Letters' => [
            'Vowels'     => 'vowels',
            'Consonants' => 'consonants',
        ],
    ];
}
```

**Searchable:** for large option lists, enable search within the dropdown:

```php
SelectFilter::make('Country')->searchable()
```

### BooleanFilter

A multi-toggle filter that lets users enable or disable multiple conditions.

```php
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Martis\Filters\BooleanFilter;

class UserFlagsFilter extends BooleanFilter
{
    public function options(Request $request): array
    {
        return [
            'Admin'    => 'is_admin',
            'Verified' => 'is_verified',
        ];
    }

    public function apply(Request $request, Builder $query, mixed $value): Builder
    {
        if (is_array($value)) {
            foreach ($value as $column => $enabled) {
                if ($enabled) {
                    $query->where($column, true);
                }
            }
        }

        return $query;
    }
}
```

The `$value` passed to `apply()` is an associative array where keys are option values and values are booleans indicating checked state.

### DateFilter

A date picker filter for filtering by a single date.

```php
use Martis\Filters\DateFilter;

use Martis\Enums\ComparisonOperator;

DateFilter::make('Created After')
    ->column('created_at')
    ->operator(ComparisonOperator::GreaterThanOrEqual)
```

Available operators: `Equals`, `GreaterThanOrEqual`, `LessThanOrEqual`, `GreaterThan`, `LessThan`.

`DateFilter` is a concrete class that can be used directly without subclassing. The `$value` is a date string in `Y-m-d` format.

**Available methods:**

| Method | Description | Default |
|--------|-------------|---------|
| `column(string)` | Database column to filter | Derived from filter name |
| `operator(string)` | Comparison operator (`=`, `>=`, `<=`, `>`, `<`) | `=` |

### DateRangeFilter

> **Martis extension** — Nova 5 does not include a built-in date range filter.

A date range filter with `from` and `to` inputs.

```php
use Martis\Filters\DateRangeFilter;

DateRangeFilter::make('Created Between')
    ->column('created_at')
```

`DateRangeFilter` is a concrete class. The `$value` is an array with optional `from` and `to` keys in `Y-m-d` format. Either bound can be omitted for an open-ended range.

## Custom Filters

All filters extend `Martis\Filters\Filter`. To create a fully custom filter, extend the base class directly:

```php
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Martis\Enums\FilterType;
use Martis\Filters\Filter;

class RecentlyActiveFilter extends Filter
{
    public function filterType(): FilterType
    {
        return FilterType::Select; // or Boolean, Date, DateRange
    }

    public function options(Request $request): array
    {
        return [
            'Last 24 hours' => '1',
            'Last 7 days'   => '7',
            'Last 30 days'  => '30',
        ];
    }

    public function apply(Request $request, Builder $query, mixed $value): Builder
    {
        return $query->where('last_active_at', '>=', now()->subDays((int) $value));
    }
}
```

## Default Values

Override the `default()` method to pre-select a filter value when the page loads:

```php
public function default(): mixed
{
    return 'active';
}
```

When a filter has a default value, the frontend applies it automatically on the initial page load. The default is included in the index query, ensuring consistent and predictable behavior from the first request.

## Authorization

> **Martis extension** — Nova 5 does not support per-filter authorization.

Use `canSee()` to control filter visibility based on the current request. This is consistent with how `canSee()` works on Fields and Actions.

```php
StatusFilter::make('Internal Rating')
    ->canSee(fn ($request) => $request->user()->isAdmin())
```

When a filter is hidden via `canSee()`:
- It is excluded from the schema response (frontend never sees it)
- Even if a client sends the filter in query params, it is ignored on the backend

## Dynamic Filters

When using the same filter class for multiple columns, pass the column in the constructor and override `uriKey()` to ensure uniqueness:

```php
class ColumnStatusFilter extends SelectFilter
{
    public function __construct(
        string $name,
        protected string $column,
        ?string $uriKey = null,
    ) {
        parent::__construct($name, $uriKey ?? Str::kebab($column));
    }

    public function apply(Request $request, Builder $query, mixed $value): Builder
    {
        return $query->where($this->column, $value);
    }

    public function options(Request $request): array
    {
        return [
            'Active'   => 'active',
            'Inactive' => 'inactive',
        ];
    }
}

// Usage:
public function filters(Request $request): array
{
    return [
        new ColumnStatusFilter('Account Status', 'account_status'),
        new ColumnStatusFilter('Billing Status', 'billing_status'),
    ];
}
```

## Metadata

Pass arbitrary metadata to the frontend using `withMeta()`:

```php
SelectFilter::make('Status')->withMeta(['placeholder' => 'Choose a status...'])
```

## Custom Component

Override the default frontend component with `componentKey()`:

```php
SelectFilter::make('Status')->componentKey('my-custom-filter')
```

## Filter Grid Layout (span)

> **Martis extension** — Nova 5 does not support filter layout control.

Control how much horizontal space each filter occupies using a 12-column grid:

```php
public function filters(Request $request): array
{
    return [
        StatusFilter::make('Status')->span(4),              // 1/3 width
        DateRangeFilter::make('Period')->span(8),            // 2/3 width
        RegionFilter::make('Region')->span(3),               // 1/4 width
        SearchFilter::make('Keyword')->span(9),              // 3/4 width
    ];
}
```

Default spans: select/boolean = 3 columns, date-range = 6 columns. Override with `->span()`.

## Filter Interaction

- Filters combine with **search**, **sort**, **pagination**, and **trashed** controls.
- Changing a filter resets the page to 1.
- Active filters are shown as **pill tags** below the filter button, visible even when the panel is closed.
- Each pill has a **per-filter clear button** (X) to remove individual filters.
- A global **"Clear all filters"** button removes all active filters at once.
- Filters are serialized as a JSON object in the `?filters=` query parameter.

## Artisan Command

Generate new filter classes with the `martis:filter` command:

```bash
# Select filter (default)
php artisan martis:filter StatusFilter

# Boolean filter
php artisan martis:filter UserFlagsFilter --boolean

# Date filter
php artisan martis:filter CreatedAfterFilter --date
```

Generated classes are placed in `app/Martis/Filters/`.

## API

### Query parameter

```
GET /api/resources/{resource}?filters={"status":"active","created-after":"2025-01-01"}
```

The `filters` parameter is a JSON-encoded object where keys are filter `uriKey` values and values are the selected filter values.

### Schema

Filter definitions are included in the schema endpoint response:

```
GET /api/resources/{resource}/schema
```

Each filter in the `filters` array includes:

```json
{
  "type": "filter",
  "filterType": "select",
  "name": "Status",
  "uriKey": "status",
  "component": null,
  "options": [
    { "label": "Active", "value": "active" },
    { "label": "Inactive", "value": "inactive" }
  ],
  "default": null,
  "meta": { "searchable": false }
}
```

Grouped options include a `group` key:

```json
{
  "options": [
    { "label": "Odd", "value": "odd", "group": "Numbers" },
    { "label": "Even", "value": "even", "group": "Numbers" },
    { "label": "Vowels", "value": "vowels", "group": "Letters" }
  ]
}
```

## Nova 5 Compatibility

The following table maps Nova 5 filter capabilities to their Martis equivalents:

| Nova 5 Capability | Martis | Status |
|---|---|---|
| `SelectFilter` | `Martis\Filters\SelectFilter` | Compatible |
| `BooleanFilter` | `Martis\Filters\BooleanFilter` | Compatible |
| `DateFilter` | `Martis\Filters\DateFilter` | Compatible |
| `apply(Request, Builder, $value)` | Same signature | Compatible |
| `options(Request)` | Same signature | Compatible |
| `default()` | Same signature | Compatible |
| `name()` | Same signature | Compatible |
| `key()` (dynamic filters) | `uriKey()` | Compatible |
| `withMeta(array)` | Same signature | Compatible |
| Grouped options | Supported | Compatible |
| AND filter composition | Same behavior | Compatible |
| Collapsible filter panel | Same behavior | Compatible |
| Artisan generator | `martis:filter` | Compatible |

## Martis Differentials

> Features unique to Martis that do not exist in Laravel Nova 5.

| Differential | Description |
|---|---|
| **DateRangeFilter** | Built-in date range filter with from/to inputs. Nova requires third-party packages. |
| **canSee() authorization** | Per-filter visibility control via `canSee()` callback. Nova does not support filter-level authorization. |
| **Active filter pills** | Pill tags showing active filters with name and value, visible even when the panel is closed. Nova only shows a generic count inside the dropdown. |
| **Per-filter clear** | Individual clear button (X) on each filter pill. Nova only supports clearing all filters at once. |
| **Default values on load** | Defaults are applied correctly on the frontend initial load and included in the first query. Nova's default implementation has known issues (GitHub #1138). |
| **Searchable select** | Built-in `searchable()` option on SelectFilter. Nova requires third-party packages. |
