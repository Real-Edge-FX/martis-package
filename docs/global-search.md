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

## ⭐ Result image / avatar — `searchImage()`

Surface a per-record image (gravatar, file thumbnail, photo) to the left of the title in the palette. Defaults to `null` so the row stays icon-only — the same as before this hook landed.

```php
public function searchImage(Model $model): ?string
{
    return $model->avatar_url
        ?? 'https://www.gravatar.com/avatar/'.md5(strtolower(trim($model->email)));
}
```

The frontend renders the URL as a 20×20 round avatar; the icon set is suppressed for that row only.

---

## ⭐ Custom result shape — `globalSearchResult()`

The bundled transformer emits `id`, `title`, `subtitle`, `image`, and `url`. Override `globalSearchResult()` on the resource to attach arbitrary fields a frontend slot is prepared to render (status badges, tags, counts):

```php
public function globalSearchResult(Model $model): array
{
    return [
        'id' => $model->getKey(),
        'title' => $this->title(),
        'subtitle' => $this->searchSubtitle($model),
        'image' => $this->searchImage($model),
        'url' => '/resources/'.static::uriKey().'/'.$model->getKey(),
        // Custom — pair with a frontend override that reads it.
        'status' => $model->status,
    ];
}
```

The default implementation already calls `searchSubtitle()` and `searchImage()` — override only when you need extra keys on the wire.

---

## ⭐ Per-field search priority — `searchPriority()`

Rank matches in higher-weight fields above matches in lower-weight ones without writing a custom `searchOrderBy()`. The Global Search resolver builds a `CASE` expression that scores each row by its highest-priority match:

```php
public function fields(Request $request): array
{
    return [
        Text::make('email')->searchable()->searchPriority(3), // canonical id
        Text::make('name')->searchable()->searchPriority(2),  // primary label
        Textarea::make('notes')->searchable(),                // priority 1 (default)
    ];
}
```

Active only on MySQL; other database drivers keep the unranked LIKE result set. Use `searchOrderBy()` for engine-agnostic custom ordering.

---

## ⭐ `field:value` query syntax

The Cmd+K input understands per-field tokens. They are parsed out of the free-text term and applied as `where(attribute, like, %value%)` constraints scoped to the named field. Whatever's left of the query (after the tokens are stripped) runs as the regular free-text search.

```
status:open                  → WHERE status LIKE %open%
admin status:active          → free-text "admin" + WHERE status LIKE %active%
title:"Quarterly Report"     → quoted value, treated as one token
```

If the named field is not declared `searchable()` on the resource, the token is silently dropped. When a user types **only** unknown tokens (no free-text fallback), the search returns no results — preferable to returning every row from a typo.

---

## ⭐ Searchable detail relations — `searchableRelations()`

Search across attributes on related models. Each entry is a dot-notation path that the resolver translates into a `whereHas`:

```php
public static function searchableRelations(): array
{
    return ['customer.name', 'customer.email'];
}
```

A query like `John` on the snippet above also matches Orders whose `customer.name` or `customer.email` contains `John`. Defaults to `[]` (own fields only).

Scout-backed resources ignore this hook — Scout indexes whatever the model exposes via `toSearchableArray()`.

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
| `items[].image` | string\|null | From `searchImage()` (avatar/thumbnail URL). |
| `items[].url` | string | `/resources/{uriKey}/{id}`. |
| `items[].*` | mixed | Any extra keys returned by `globalSearchResult()`. |
| `total` | int (optional) | Present **only** when `items.length === limit`. |
| `viewAllUrl` | string | Always present. The `q` parameter is `rawurlencode`d so spaces and symbols round-trip safely. |

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

- If the model uses `Laravel\Scout\Searchable` AND the resource has not overridden `public static function usesScout(): bool` to return `false`, Scout drives the search.
- Otherwise, the standard database `LIKE %term%` pipeline runs against fields marked `searchable()`. `searchPriority()`, `searchableRelations()`, and the `field:value` query syntax all run on this path; Scout is left to its own ranking and indexed shape.

Both paths integrate identically with the Cmd+K palette — `searchOrderBy()` runs in either case.

### Case-insensitivity across drivers

The `LIKE` pipeline is **case-insensitive on every supported database**. PostgreSQL's `LIKE` is case-sensitive, so the resolver emits `ILIKE` there; MySQL, SQLite, and SQL Server treat `LIKE` case-insensitively already and keep `LIKE` (byte-for-byte unchanged). The operator is chosen once per query by `SearchResolver::likeOperator($driver)` — the single source of truth reused by both the global-search pipeline and the relatable / BelongsTo dropdown search in `ResourceController`. So `RP` and `rp` return the same results regardless of the underlying database. This mirrors Laravel's own `whereLike($col, $val, caseSensitive: false)` default; column type / collation is irrelevant.

See [resources.md](resources.md#laravel-scout-integration) for full Scout integration notes.

---

## Cmd+K palette sections

The palette renders six kinds of sections, each grown from a different data source:

1. **Resources** — every resource the user can view, filtered by query (client-side).
2. **⭐ Tools** — every registered custom Tool the user is authorised to see (same `authorizedToSee` gate as the sidebar), so ⌘K can jump to a Tool by name just like a resource. Each links to `/tools/{uriKey}`.
3. **Actions** — standalone resource actions (`showInline=false`, `standalone=true`), filtered by query.
4. **Recent activity** — the last 5 records the user opened (drawn from action events).
5. **⭐ Recent searches** — the user's last 5 successful queries, persisted in `sessionStorage`. Visible only when the input is empty. Clicking re-runs the query.
6. **Records** — live hits per resource via `/api/search`. Each resource gets its own labelled section with the optional "View all" footer.

Sections 1–4 are loaded from `/api/command-palette` once per session and cached for 30 s. The Recent searches section is purely client-side (no backend, no roundtrip). The Records section debounces 300 ms before firing `/api/search`.

### Section length cap

The **Resources**, **Tools**, and **Actions** sections are each capped at **5 visible rows**. When a section has more, it's trimmed and gets a subtle **"Show N more"** row; clicking it (or pressing Enter on it) reveals the rest of that section in place — no navigation. This keeps the empty-state palette scannable: an app with 50 resources shows 5 + "Show 45 more" instead of dumping the full catalogue before the user types anything. Record groups (which carry their own "View all" footer) and the already-short recent sections are exempt from the cap.

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
