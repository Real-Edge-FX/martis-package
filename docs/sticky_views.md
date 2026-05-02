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

Filter state, sort and pagination are already reflected in the URL — every view is deep-linkable. Sticky Views complement this by restoring the last-used URL parameters when the user lands on an index page without explicit URL state (for example, from the sidebar link or a fresh tab).

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

The persistence logic lives in an internal module (`lib/useStickyView`). Most developers never touch it directly — `ResourceIndexPage` wires it automatically.

The two affordances exposed to consumers are:

- **"Reset view" button** — built into the index toolbar, visible when saved state differs from resource defaults.
- **"Clear saved views"** — available in the user profile / preferences panel; clears all sticky-view entries from storage.

If you are building a fully custom index component and need to read or write sticky state programmatically, open an issue — a stable public API is planned once the `scope=server` iteration is complete.

## Future iterations

- **`scope=server`** — sync state to a `martis_user_view_state` JSON column / table. Multi-device support.
- **Named views** — user saves "Active clients in EU" as a named preset and recalls it from a dropdown. Shareable URLs.
- **Column visibility toggle** — once the column-toggle UI ships, the `columns` bucket starts capturing real state.
