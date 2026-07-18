/**
 * Per-row action visibility for pivot relationship panels
 * (BelongsToMany / MorphToMany), shared by both field components so their
 * logic cannot drift.
 *
 * The pivot fields render two possible per-row controls — edit-pivot and
 * detach — inside `RelationshipTableShell`'s trailing "Actions" column. That
 * column collapses when the shell receives no row actions, but only if the
 * field passes `rowActionsExtras={undefined}` when nothing would render:
 * `RelationshipTableShell` keys the column on `!!rowActionsExtras` (the
 * callback's PRESENCE), so an always-passed callback that returns an empty
 * fragment leaves a permanently blank Actions column on a fully read-only
 * panel (RP-139). `hasAny` is the signal the field uses to decide whether to
 * pass the callback at all.
 */
export interface PivotRowActionVisibility {
  showEditPivot: boolean
  showDetach: boolean
  /** True when at least one per-row control renders. When false the field
   *  must omit `rowActionsExtras` so the Actions column collapses. */
  hasAny: boolean
}

export function pivotRowActions(opts: {
  readOnly: boolean
  pivotFieldsCount: number
  canDetach: boolean
  hideEditAction: boolean
  hideDeleteAction: boolean
}): PivotRowActionVisibility {
  const showEditPivot = !opts.readOnly && opts.pivotFieldsCount > 0 && !opts.hideEditAction
  const showDetach = !opts.readOnly && opts.canDetach && !opts.hideDeleteAction

  return { showEditPivot, showDetach, hasAny: showEditPivot || showDetach }
}
