# Lenses

Lenses are alternative, resource-scoped views onto the dataset of a
Martis Resource. They mirror Laravel Nova 5's lens concept — every
lens is a subclass that composes its own query, fields, filters,
cards, actions and optional polling cadence — and add a small set of
Martis-specific extensions that are marked below.

## Quick start

Generate a lens with the artisan command:

```bash
php artisan martis:lens MostValuableClients
```

The generator creates `app/Martis/Lenses/MostValuableClients.php`
with the following minimum surface:

```php
namespace App\Martis\Lenses;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Martis\Http\Requests\LensRequest;
use Martis\Lenses\Lens;

class MostValuableClients extends Lens
{
    public function query(LensRequest $request, Builder $query): Builder
    {
        return $request->withOrdering(
            $request->withFilters($query),
            fn (Builder $q) => $q->latest(),
        );
    }

    public function fields(Request $request): array
    {
        return [/* ... */];
    }
}
```

Register it on a Resource:

```php
public function lenses(Request $request): array
{
    return [
        new MostValuableClients(),
    ];
}
```

The admin UI gets a "Lenses" dropdown on the resource index. Selecting
the lens navigates to `/resources/{resource}/lens/{lens}`.

## Nova v5 parity

The base class exposes the same contract Nova developers expect:

| Hook | Signature | Default |
|---|---|---|
| `query` | `public function query(LensRequest, Builder): Builder\|Paginator` | **abstract** |
| `fields` | `public function fields(Request): array` | `[]` |
| `cards` | `public function cards(Request): array` | `[]` |
| `filters` | `public function filters(Request): array` | `[]` |
| `actions` | `public function actions(Request): array` | `[]` (controller falls back to resource actions) |
| `name` | `public function name(): string` | title-case of class basename minus `Lens` suffix |
| `uriKey` | `public function uriKey(): string` | kebab-case of class basename minus `Lens` suffix |

`perPage` and `perPageOptions` are **static methods** (identical shape to
`Resource::perPage()` / `Resource::perPageOptions()`). Polling knobs are
static properties:

```php
public static function perPageOptions(): array
{
    return [10, 25, 50, 100];
}

public static function perPage(): int
{
    return 50;
}

public static bool $polling = true;
public static int $pollingInterval = 60;
public static bool $showPollingToggle = true;
```

### Effective per-page

Lenses follow the same clamp rule as resources: when the value returned by `perPage()` is not in `perPageOptions()`, Martis silently clamps to `perPageOptions()[0]`. The clamped value is what the lens schema payload (`meta.perPage`) actually exposes to the UI.

```php
public static function perPageOptions(): array { return [10, 25, 50, 100]; }
public static function perPage(): int          { return 7; } // not in options

// Lens::resolvedPerPage() === 10 — clamped to first option.
```

Use `Lens::resolvedPerPage(): int` when the backend needs the single
"effective" per-page value; never call `perPage()` directly if you want
the clamped result. See [resources.md — Effective per-page](resources.md#effective-per-page)
for the full ruleset (Rules 1–3 apply identically here).

### Query composition helpers

`LensRequest` exposes two composition helpers matching the Nova API:

- `withFilters(Builder): Builder` — applies filter values selected by
  the user.
- `withOrdering(Builder, Closure $default = null): Builder` — applies
  the user's chosen sort column; if none, calls the default closure.

```php
public function query(LensRequest $request, Builder $query): Builder
{
    return $request->withOrdering(
        $request->withFilters($query),
        fn (Builder $q) => $q->orderByDesc('monthly_revenue'),
    );
}
```

### Inheritance from the parent resource

When the lens does not declare its own override, it inherits the value
from the parent `Resource`:

| Member | Lens default | Inheritance rule |
|---|---|---|
| `filters(Request)` | `[]` | Inherited when the lens did **not** override the method. |
| `actions(Request)` | `[]` | Inherited when the lens did **not** override the method. |
| `$perPageOptions` | `null` | Inherited from `Resource::perPageOptions()` when null. |
| `$perPage` | `null` | Inherited from `Resource::perPage()` when null. |

**Override semantics:** whenever the lens **does** override the hook,
its return value wins verbatim — including an explicit empty array.
That is how you disable filters on a lens:

```php
public function filters(Request $request): array
{
    return []; // the resource's filters are intentionally suppressed
}
```

The controller uses PHP reflection (`Lens::hasOverride(string $method)`)
to tell an implicit fallback apart from a deliberate empty return.

Trashed (soft-delete) filtering is honoured automatically: if the
parent resource uses `SoftDeletes`, the lens page surfaces the same
Active / With trashed / Only trashed selector as the index, and the
`?trashed=` query param is applied to the base query before the lens
runs its own `query()`.

### Opting individual filters out of lens inheritance

A `Filter` can call `->excludeFromLens()` to signal "this filter is only
for the default index; don't inherit it into lenses". Works alongside
the automatic inheritance — lenses that override `filters()` are
unaffected.

```php
public function filters(Request $request): array
{
    return [
        // Appears on the index AND every inheriting lens.
        StatusFilter::make('Estado', 'status'),

        // Appears only on the index — lenses inheriting the resource's
        // filters skip this one.
        InternalNotesFilter::make('Notas', 'notes')->excludeFromLens(),
    ];
}
```

### Summary aggregation

The `summary()` hook receives the **lens-filtered query** — the same
query `Lens::query()` composes, including user filters and search —
not the untouched base query. So a summary over `OverdueInvoices`
really does sum only overdue rows, even when the invoices table holds
thousands of non-matching rows.

If `Lens::query()` returns a `Paginator` directly, the summary is
skipped (the developer has opted into manual pagination and is
expected to derive totals themselves).

### Authorization

- `canSee(Closure $callback)` — Nova parity: closure that receives the
  request and returns bool.
- `canSeeWhen(string $ability, mixed $args = null)` — Nova shorthand
  delegating to `$user->can($ability, $args)`.

When the closure denies, the lens is stripped from the schema and a
direct GET to `/resources/{r}/lenses/{lens}` responds with HTTP 403.
See [authorization.md](authorization.md) for the broader policy
contract.

## Martis extensions

### D1 — Sticky summary row

> Nova 5 does not have a built-in summary row. In Nova you create a
> separate Metric card for totals.

Return aggregates from `summary()` and the UI renders them as a sticky
row below the lens table:

```php
public function summary(Request $request, Builder $query): array
{
    return [
        'clients' => ['label' => 'Clients', 'value' => $query->count()],
        'revenue' => ['label' => 'Monthly Revenue', 'value' => $query->sum('monthly_revenue')],
    ];
}
```

Served inside the paginated response meta as
`meta.summary[key] = { label, value, format? }`.

### D2 — Declarative query cache (with auto-invalidation)

> Nova 5 has no built-in cache. Pass `cacheFor` to stop paying the
> cost of heavy joins on every pageload.

```php
public function lenses(Request $request): array
{
    return [
        (new MostValuableClients())->cacheFor(60),              // seconds
        (new MostValuableClients())->cacheFor(new DateInterval('PT5M')),
    ];
}
```

The cache key mixes the lens `uriKey`, active filters, search, sort
column, sort direction, page, trashed mode **and a per-table signature**
— `COUNT(*) + MAX(updated_at)` taken right before the cache lookup.
Any insert, update or delete on the model bumps the signature, so the
next request automatically misses the cache without any observers or
cache tags. TTL `0` (default) disables the cache.

### D3 — Default filters pre-applied

> Nova 5 always opens a lens with no filters. Typical product
> dashboards want the lens to open in a useful state.

```php
(new MostValuableClients())
    ->withDefaultFilters(['plan' => 'enterprise']);
```

The first load hydrates the URL with those values. If the user clears
them manually, the empty state is respected; the defaults do not
re-populate.

### D4 — URL state sync (frontend)

All lens view state — search, filters, sort column, sort direction,
page — lives in the URL query string. Copy-paste a URL and you
reproduce the exact view. Reloading the page restores the view from
the URL, no local storage involved.

## Registering custom components

`->componentKey('custom-lens-card')` lets the frontend swap out the
default table entirely, as long as the custom component is registered
in the component registry and can consume the paginated payload.

## Routing reference

| Method | URL | Controller |
|---|---|---|
| `GET` | `/martis/api/resources/{resource}/lenses/{lens}` | `LensController@index` |

The frontend route is `/martis/resources/{resource}/lens/{lens}` (UI
tree), powered by `ResourceLensPage`.

### Payload shape

The lens endpoint returns a standard Martis paginated envelope plus a
rich `meta` block that lets the UI render the lens chrome without a
second round-trip:

```jsonc
{
  "data": [/* records serialized with the lens-declared fields */],
  "meta": {
    "current_page": 1, "last_page": 1, "per_page": 25,
    "from": 1, "to": 9, "total": 9,
    "fields": [/* FieldDefinition[] as declared by the lens */],
    "actions": [/* ActionMeta[], inherited from the resource unless the lens overrides */],
    "perPageOptions": [25, 50, 100],
    "polling": true, "pollingInterval": 60, "showPollingToggle": true,
    "defaultFilters": {"plan": "enterprise"},
    "summary": {/* only when Lens::summary() returned values */}
  },
  "links": {"first": "...", "last": "...", "prev": null, "next": null}
}
```

`meta.fields` is the authoritative source for the index columns. The
UI must not fall back to `schema.fieldsForIndex` — that would leak
the resource's columns into the lens view.

## Test harness

```bash
./vendor/bin/pest --filter="LensControllerTest|LensDifferentialsTest"
```

The package ships 17 tests covering every parity bullet and every
differential. Add your own specs that subclass `Martis\Lenses\Lens`
— the only hard requirement is a concrete `query()` method.
