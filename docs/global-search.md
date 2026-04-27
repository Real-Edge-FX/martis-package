# Global Search

> The Cmd+K command palette and the underlying `/api/search` endpoint.

Martis ships a single, unified search surface accessible from anywhere in the SPA via `Cmd+K` (macOS) / `Ctrl+K` (Windows / Linux) or `/`. It searches every resource the authenticated user can view, grouped by resource label, with optional Scout integration.

This page covers:

- The `Resource` API: opt-in, per-resource limits, custom ordering, subtitles.
- The `/api/search` endpoint contract.
- The Cmd+K palette UX (sections, "View all", recent activity).
- Configuration knobs in `config('martis.search')`.

---

## Opting in

A resource participates in global search when its `globallySearchable()` returns a truthy value.

```php
class ProjectResource extends Resource
{
    public static function model(): string { return Project::class; }

    // Default — every resource is opted in.
    public static function globallySearchable(): bool|array
    {
        return true;
    }
}
```

Opt out by returning `false`:

```php
public static function globallySearchable(): bool|array
{
    return false;
}
```

---

## ⭐ Per-resource search config

`globallySearchable()` accepts an array shape so a single resource can override the global defaults without changing every other resource:

```php
public static function globallySearchable(): bool|array
{
    return [
        'enabled' => true,    // optional; defaults to true when an array is returned
        'limit' => 10,        // max results returned per resource (default 5)
        'min_query' => 1,     // min chars before this resource is queried (default 2)
    ];
}
```

| Key | Default source | When to override |
|---|---|---|
| `enabled` | `true` | Treat the array form as a "true with overrides"; you rarely need to set this explicitly. |
| `limit` | `config('martis.search.default_limit', 5)` | Bump for small high-traffic tables (clients, users). Drop for huge tables to bound payload. |
| `min_query` | `config('martis.search.min_query', 2)` | Set to `1` for tag-style resources where single-character queries are valid. |

Any omitted key falls back to the global default — no need to repeat all three.

The legacy bool return is preserved unchanged: `true` means "opt in with global defaults", `false` means "do not search".

---

## ⭐ Custom result ordering — `searchOrderBy()`

By default, search results inherit the resource's `indexQuery()` ordering. To rank results differently while searching (without affecting the regular index page), override `searchOrderBy()`:

```php
use Illuminate\Database\Eloquent\Builder;

public function searchOrderBy(Builder $query, string $term): Builder
{
    $like = addcslashes($term, '%_').'%';

    // Boost prefix matches over substring matches, then fall through to alpha order.
    return $query
        ->orderByRaw('CASE WHEN name LIKE ? THEN 0 ELSE 1 END', [$like])
        ->orderBy('name');
}
```

The hook runs **after** `SearchResolver::apply()` so any `orderBy()` you add operates on the already-filtered set. Default implementation is a no-op.

---

## ⭐ "View all" footer per group

When a resource has more matches than its `limit` allows, the API returns:

- `total` — the real number of matches (computed via a single `COUNT(*)` after the search filter, only when overflow happens).
- `viewAllUrl` — `/resources/{uriKey}?search={query}` so the user lands on the resource index with the search pre-filled.

The Cmd+K palette renders a final item per group:

> ➜ View all 47 in Projects

Click navigates to the index, where the resource's full search UI is available (filters, sort, pagination, etc.). For groups whose `items.length < limit`, no "View all" item is rendered.

---

## Result subtitles

Each item in the palette can show a secondary line under its title. Override `searchSubtitle()` on the resource:

```php
use Illuminate\Database\Eloquent\Model;

public function searchSubtitle(Model $model): ?string
{
    return $model->email;  // shown grey under "Jane Doe"
}
```

Return `null` to suppress the subtitle for a record.

---

## API contract

`GET /martis/api/search?q=<query>` (auth required).

```json
{
  "results": [
    {
      "resource": "projects",
      "label": "Projects",
      "items": [
        {
          "id": 17,
          "title": "Cloud Infrastructure",
          "subtitle": "Sarah Johnson",
          "url": "/resources/projects/17"
        }
      ],
      "total": 47,
      "viewAllUrl": "/resources/projects?search=Cloud"
    }
  ]
}
```

| Field | Type | Notes |
|---|---|---|
| `resource` | string | The resource's `uriKey()`. |
| `label` | string | Translated resource label (used as the section heading). |
| `items` | array | Up to `limit` records. |
| `items[].id` | int\|string | Primary key. |
| `items[].title` | string | From `Resource::title()`. |
| `items[].subtitle` | string\|null | From `searchSubtitle()`. |
| `items[].url` | string | `/resources/{uriKey}/{id}`. |
| `total` | int (optional) | Present **only** when `items.length === limit`. |
| `viewAllUrl` | string | Always present. |

The endpoint never returns an `items` array smaller than 1 — empty groups are filtered out before serialisation.

---

## Configuration

`config/martis.php`:

```php
'search' => [
    'default_limit' => (int) env('MARTIS_SEARCH_DEFAULT_LIMIT', 5),
    'min_query' => (int) env('MARTIS_SEARCH_MIN_QUERY', 2),
],
```

| Env var | Default | Purpose |
|---|---|---|
| `MARTIS_SEARCH_DEFAULT_LIMIT` | `5` | Max results per resource group, unless the resource's `globallySearchable()` overrides. |
| `MARTIS_SEARCH_MIN_QUERY` | `2` | Min query length before a resource is queried, unless the resource overrides. |

Both values are clamped to a minimum of 1 at request time, so a misconfigured environment never disables the endpoint entirely.

---

## Scout vs LIKE

`SearchResolver` decides per-resource:

- If the model uses `Laravel\Scout\Searchable` AND the resource has not overridden `usesScout(): false`, Scout drives the search.
- Otherwise, the standard database `LIKE %term%` pipeline runs against fields marked `searchable()`.

Both paths integrate identically with the Cmd+K palette — `searchOrderBy()` runs in either case.

See [resources.md](resources.md#laravel-scout-integration) for full Scout integration notes.

---

## Cmd+K palette sections

The palette renders four kinds of sections, each grown from a different data source:

1. **Resources** — every resource the user can view, filtered by query (client-side).
2. **Actions** — standalone resource actions (`showInline=false`, `standalone=true`), filtered by query.
3. **Recent activity** — the last few records the user opened (drawn from action events).
4. **Records** — live hits per resource via `/api/search`. Each resource gets its own labelled section with the optional "View all" footer.

The first three are loaded from `/api/command-palette` once per session and cached for 30 s. The Records section debounces 300 ms before firing `/api/search`.

---

## Testing

Coverage lives in `tests/Feature/SearchControllerTest.php`. The fixture pattern uses tiny in-test resources (`SearchTestPostResource`, etc.) that exercise the contract surface without depending on the playground.

Useful patterns when writing your own search tests:

```php
config()->set('martis.search.default_limit', 3);

$registry = app(\Martis\ResourceRegistry::class);
$registry->flush();
$registry->register(MyResource::class);

$data = $this->getJson('/martis/api/search?q=Test')->json();
expect($data['results'][0]['items'])->toHaveCount(3);
```
