/**
 * Action surface partitioning — the single source of truth for which actions
 * appear in the index toolbar dropdown vs. as a per-row inline button.
 *
 * These flags mirror the backend `Action` visibility model:
 *   - `showOnIndex` (default true) — the action appears in the index dropdown.
 *   - `showInline`  (default false) — the action ALSO gets a per-row button.
 *
 * `showInline()` on the backend only sets `showInline = true`; it does NOT
 * clear `showOnIndex`. So an action flagged `showInline` is ADDITIVE: it shows
 * in the dropdown AND as a per-row button. A per-row-only action uses
 * `onlyInline()` (which sets `showOnIndex = false`). Keeping this logic in one
 * tested function stops ResourceIndex and ResourceLens from drifting apart or
 * re-introducing the "inline actions vanish from the dropdown" bug.
 */
export interface ActionSurfaceFlags {
  showOnIndex: boolean
  showInline: boolean
}

/**
 * Actions shown in the index toolbar dropdown: every action flagged
 * `showOnIndex`. `showInline` is additive and must NOT exclude an action here.
 */
export function filterIndexActions<T extends ActionSurfaceFlags>(actions: T[]): T[] {
  return actions.filter((a) => a.showOnIndex)
}

/** Actions rendered as a per-row inline button. */
export function filterInlineActions<T extends ActionSurfaceFlags>(actions: T[]): T[] {
  return actions.filter((a) => a.showInline)
}
