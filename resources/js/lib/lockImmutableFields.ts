/**
 * The form items an update form renders. An `immutable()` field is written
 * on create only: every update endpoint skips it, so the update form gets it
 * as `readonly` and its input disables itself exactly as for `readonly()`.
 *
 * Walks the layout containers (`panel`, `section`, `tab_group` and a panel
 * inside a tab). A field inside a Repeater row is left alone here: a row
 * writes an immutable field when it is new and keeps it once stored, so the
 * Repeater locks it itself, on the rows the record stores (see
 * `RepeaterField`). Items with nothing to lock keep their identity, and so
 * does the list when no field is immutable.
 */
export function lockImmutableFields<T>(items: T[]): T[] {
  return lockList(items as unknown[]) as T[]
}

function lockList(list: unknown[]): unknown[] {
  let changed = false
  const next = list.map((item) => {
    const locked = lockItem(item as Record<string, unknown>)
    if (locked !== item) changed = true
    return locked
  })
  return changed ? next : list
}

function lockItem(item: Record<string, unknown>): Record<string, unknown> {
  if (item.type === 'panel' || item.type === 'section') {
    const fields = (item.fields as unknown[] | undefined) ?? []
    const locked = lockList(fields)
    return locked === fields ? item : { ...item, fields: locked }
  }

  if (item.type === 'tab_group') {
    const tabs = (item.tabs as Array<Record<string, unknown>> | undefined) ?? []
    let changed = false
    const nextTabs = tabs.map((tab) => {
      const fields = (tab.fields as unknown[] | undefined) ?? []
      const locked = lockList(fields)
      if (locked === fields) return tab
      changed = true
      return { ...tab, fields: locked }
    })
    return changed ? { ...item, tabs: nextTabs } : item
  }

  return item.immutable && !item.readonly ? { ...item, readonly: true } : item
}
