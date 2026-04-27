# Sticky Views

> Per-user view state persistence on resource index pages — filters, sort, pagination, per-page selector and search query survive navigation.

## What it does

A user applies a filter, opens a record, and clicks back. The table is **exactly** as they left it. Same filter, same sort, same page, same search query. No need to redo the work.

The state is scoped per-resource and per-tab, so each resource remembers its own view independently and a fresh tab starts clean.

## Why it matters

Most admin panels forget the user's setup the moment they navigate away. It's small enough that nobody asks for it, but every user feels the friction every day. Sticky Views remove the friction without any developer setup — it just works.

## How it works

The implementation uses a hybrid model:

1. **State lives in React** as today (`page`, `sortBy`, `activeFilters`, etc.).
2. On every meaningful change, `useStickyView()` writes the state into `sessionStorage` under `martis:view:{uriKey}` (one entry per resource).
3. When the user lands back on the index page, the saved state is restored before the first render — no flicker.
4. Closing the tab wipes `sessionStorage` so a fresh window starts clean.

URL-as-source-of-truth deep-linking is on the roadmap (next iteration); the current cut already covers the daily pain.

## What gets persisted

| Bucket | What | Default |
|--------|------|---------|
| `filters` | Active filter values, search query, soft-delete toggle | persisted |
| `sorting` | Active sort column + direction | persisted |
| `pagination` | Current page number | persisted |
| `per_page` | Per-page selector value | persisted |
| `columns` | Column visibility toggles (forward-looking) | persisted |
| `scroll` | Scroll position | not persisted |

Each bucket can be toggled off independently via `config('martis.sticky_views.persist')`.

## Configuration

```php
// config/martis.php
'sticky_views' => [
    'enabled' => env('MARTIS_STICKY_VIEWS_ENABLED', true),
    'scope' => env('MARTIS_STICKY_VIEWS_SCOPE', 'session'),
    'persist' => [
        'filters' => true,
        'sorting' => true,
        'pagination' => true,
        'per_page' => true,
        'columns' => true,
        'scroll' => false,
    ],
],
```

### `enabled`
Master switch. Set to `false` (or `MARTIS_STICKY_VIEWS_ENABLED=false`) to disable the feature globally. State already saved in storage is ignored.

### `scope`
Where the state is persisted:

- `session` (default) — `sessionStorage`. Wipes when the tab closes. Best fit for "I want my work to survive a back button click but not span sessions".
- `local` — `localStorage`. Survives tab close. Use when users routinely close the tab during a workflow.
- `server` — reserved for the next iteration. Will store state in the user's preferences row so views follow the user across devices.

### `persist`
Per-bucket toggles. Set any bucket to `false` to keep that part of the view non-sticky. Useful when a resource's pagination is naturally chronological (e.g. an audit log that always wants to start on page 1).

## Per-resource opt-out

Some resources should always start clean — audit logs that should default to "today first", or live-data tables where stale state is misleading. Opt out by setting a static property on the Resource:

```php
class AuditEventResource extends Resource
{
    protected static bool $stickyView = false;
}
```

The frontend respects this via the `stickyView` flag in the schema payload — when false, no state is read or written for this resource.

## Reset View

When the saved view differs from the resource defaults, an `Reset view` button appears on the index toolbar. Clicking it:

1. Drops the `martis:view:{uriKey}` storage entry.
2. Resets every state bucket back to the resource's defaults (`defaultSort`, `defaultSortDirection`, no filters, page 1, etc.).

The button is hidden when nothing would change.

## API

The feature is implemented as a small library exported from `@martis/martis/lib/useStickyView`:

```ts
import {
  useStickyView,        // hook — writes state into storage on change
  readStickyView,       // imperative read (used during restore)
  writeStickyView,      // imperative write
  clearStickyView,      // drop one resource's saved state
  clearAllStickyViews,  // drop every Martis sticky-view entry
} from '@martis/martis/lib/useStickyView'
```

Most consumers won't touch this directly — `ResourceIndexPage` already wires it. The imperative helpers are exposed for custom index components and for the user profile / preferences "Clear saved views" affordance.

## Future iterations

- **`scope=server`** — sync state to a `martis_user_view_state` JSON column / table. Multi-device support.
- **URL-as-source-of-truth** — every state change reflects in `?` query params so the current view is shareable / deep-linkable.
- **Named views** — user saves "Active clients in EU" as a named preset and recalls it from a dropdown. Shareable URLs.
- **Column visibility toggle** — once the column-toggle UI ships, the `columns` bucket starts capturing real state.
