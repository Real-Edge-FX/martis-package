# Filters

Filters allow users to narrow down resource index results by applying criteria. They appear as a collapsible panel on the resource index page with active filter pills for at-a-glance visibility.

Martis ships four built-in filter types and supports custom filters for advanced use cases.

## Defining Filters

Register filters on a resource by overriding the `filters()` method:

```php
use Illuminate\Http\Request;
use Martis\Enums\ComparisonOperator;

public function filters(Request $request): array
{
    return [
        StatusFilter::make('Status'),
        RoleFilter::make('Role'),
        DateFilter::make('Created After')
            ->column('created_at')
            ->operator(ComparisonOperator::GreaterThanOrEqual),
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

### MultiSelectFilter

> Since **v1.31.0**.

A searchable dropdown that lets users pick **multiple** values — an "any of these" facet (e.g. a growing set of tags). The selected value is an **array** of the chosen `value`s, so `apply()` is typically a `whereIn()` (ANY-match). Mirrors `SelectFilter` (override `options()`, enable search with `->searchable()`); the React side renders a PrimeReact MultiSelect.

Martis skips empty selections before `apply()` runs — an empty array carries no constraint, so it shows all records — which means you never have to guard against `whereIn('col', [])` compiling to `WHERE 0 = 1`. Add your own `empty($value)` guard only if you call `apply()` directly outside the request pipeline.

```php
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Martis\Filters\MultiSelectFilter;

class TagsFilter extends MultiSelectFilter
{
    public function options(Request $request): array
    {
        return ['PHP' => 'php', 'Laravel' => 'laravel', 'Vue' => 'vue'];
    }

    public function apply(Request $request, Builder $query, mixed $value): Builder
    {
        return $query->whereHas('tags', fn ($q) => $q->whereIn('slug', (array) $value));
    }
}
```

```php
// in the resource
public function filters(Request $request): array
{
    return [TagsFilter::make('Tags')->searchable()];
}
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
| `column(string $column)` | Database column to filter. | Derived from filter name |
| `operator(ComparisonOperator $operator)` | Typed comparison operator. Cases: `Equals`, `GreaterThanOrEqual`, `LessThanOrEqual`, `GreaterThan`, `LessThan`. | `ComparisonOperator::Equals` |

### DateRangeFilter

> **Martis extension** — built-in date range filter.

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

> **Martis extension** — per-filter authorization.

Use `canSee()` to control filter visibility based on the current request. This is consistent with how `canSee()` works on Fields and Actions.

```php
StatusFilter::make('Internal Rating')
    ->canSee(fn ($request) => $request->user()->isAdmin())
```

When a filter is hidden via `canSee()`:
- It is excluded from the schema response (frontend never sees it)
- Even if a client sends the filter in query params, it is ignored on the backend

## Excluding a Filter from Lenses

> **Martis extension** — keep a filter on the default index but skip it on lenses.

By default, lenses inherit `Resource::filters()` so the lens toolbar shows the same filters as the parent index. When a lens has a different intent (e.g. an audit log that always renders "today first" regardless of operator filtering), opt the filter out per-instance:

```php
public function filters(Request $request): array
{
    return [
        StatusFilter::make('Status'),
        DateRangeFilter::make('Period')->excludeFromLens(),
    ];
}
```

`excludeFromLens()` accepts a single boolean (defaults to `true`). The opt-out is per-filter and per-resource — each lens that builds its own `filters()` from scratch is unaffected. Use `isExcludedFromLens(): bool` to query the flag.

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

Override the default frontend control with `componentKey()`. Since **v1.31.0** the filter panel resolves the key from the component registry (mirroring cards, metrics, and fields) and renders your component with a `{ filter, value, onChange }` contract; register it from your consumer-extension bundle (`resources/js/martis-extensions/`). Before v1.31.0 the key was serialized but the SPA ignored it.

```php
SelectFilter::make('Status')->componentKey('my-custom-filter')
```

```tsx
// resources/js/martis-extensions/filters/MyCustomFilter.tsx
export default function MyCustomFilter({ filter, value, onChange }: {
  filter: FilterDefinition; value: unknown; onChange: (value: unknown) => void
}) {
  // render any control; call onChange(newValue) to update the active filter
}
```

## Filter Grid Layout (span)

> **Martis extension** — filter layout control via spans.

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

## Placeholder

> **Martis extension** — placeholder distinct from the filter name.

By default, a filter's empty-state placeholder (shown in the select/date control before a value is chosen) is the same as its display label. Use `->placeholder()` to make them differ:

```php
SelectFilter::make('Project')->searchable()->placeholder('Select…')
```

Here the field label above the control still reads "Project", but the dropdown itself shows "Select…" until a value is picked. Passing `null` (or omitting the call) restores the default behaviour of falling back to the filter name.

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
  "placeholder": null,
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

## Martis Differentials

> Features unique to Martis.

| Differential | Description |
|---|---|
| **DateRangeFilter** | Built-in date range filter with from/to inputs. |
| **canSee() authorization** | Per-filter visibility control via `canSee()` callback. |
| **Active filter pills** | Pill tags showing active filters with name and value, visible even when the panel is closed. |
| **Per-filter clear** | Individual clear button (X) on each filter pill. |
| **Default values on load** | Defaults are applied correctly on the frontend initial load and included in the first query. |
| **Searchable select** | Built-in `searchable()` option on SelectFilter. |
