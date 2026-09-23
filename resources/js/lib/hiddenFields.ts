import { useMemo } from 'react'

/**
 * The fields a record hides (v1.38.0).
 *
 * The server leaves the value of a field `canSeeForModel()` hides for a
 * record out of that record and lists the field's attribute under `_hidden`.
 * The schema describes the resource, not the record, so its field lists still
 * hold the field: a page that renders them against a record leaves the fields
 * the record hides out, instead of rendering them empty (a hidden `Boolean`
 * would read "No").
 */
type RecordLike = object | null | undefined

function hiddenList(record: RecordLike): string[] {
  const hidden = (record as { _hidden?: unknown } | null | undefined)?._hidden
  return Array.isArray(hidden) ? hidden.filter((attribute): attribute is string => typeof attribute === 'string') : []
}

/** The attributes of the fields `record` hides; empty for no record. */
export function hiddenAttributes(record: RecordLike): ReadonlySet<string> {
  return new Set(hiddenList(record))
}

/** Whether `record` hides the field with this attribute. */
export function isHiddenOn(record: RecordLike, attribute: string): boolean {
  return hiddenList(record).includes(attribute)
}

/**
 * `hiddenAttributes()` as a set that keeps its identity while the record
 * hides the same fields, so a field list memoised on it is not rebuilt each
 * time the record is fetched again.
 */
export function useHiddenAttributes(record: RecordLike): ReadonlySet<string> {
  const key = hiddenList(record).join('\u0000')
  return useMemo(() => new Set(key === '' ? [] : key.split('\u0000')), [key])
}

/**
 * The items of a field list without the fields whose attribute `hidden`
 * holds. Walks the layout containers (`panel`, `section`, `tab_group` and a
 * panel inside a tab): a container keeps its other fields and is left out
 * when it keeps none, as is a tab. Items with nothing to leave out keep their
 * identity, and so does the list when `hidden` is empty.
 */
export function withoutHiddenFields<T>(items: T[], hidden: ReadonlySet<string>): T[] {
  if (hidden.size === 0) return items
  return keepList(items as unknown[], hidden) as T[]
}

/** The list without the hidden fields, or the same list when it holds none. */
function keepList(list: unknown[], hidden: ReadonlySet<string>): unknown[] {
  let changed = false
  const next: unknown[] = []
  for (const item of list) {
    const kept = keepItem(item as Record<string, unknown>, hidden)
    if (kept !== item) changed = true
    if (kept !== null) next.push(kept)
  }
  return changed ? next : list
}

/** The item without the hidden fields, the same item when it holds none, or null when none of it is left. */
function keepItem(item: Record<string, unknown>, hidden: ReadonlySet<string>): Record<string, unknown> | null {
  if (item.type === 'panel' || item.type === 'section') {
    const fields = (item.fields as unknown[] | undefined) ?? []
    const kept = keepList(fields, hidden)
    if (kept === fields) return item
    return kept.length > 0 ? { ...item, fields: kept } : null
  }

  if (item.type === 'tab_group') {
    const tabs = (item.tabs as Array<Record<string, unknown>> | undefined) ?? []
    let changed = false
    const nextTabs: Array<Record<string, unknown>> = []
    for (const tab of tabs) {
      const fields = (tab.fields as unknown[] | undefined) ?? []
      const kept = keepList(fields, hidden)
      if (kept === fields) {
        nextTabs.push(tab)
        continue
      }
      changed = true
      if (kept.length > 0) nextTabs.push({ ...tab, fields: kept })
    }
    if (!changed) return item
    return nextTabs.length > 0 ? { ...item, tabs: nextTabs } : null
  }

  return typeof item.attribute === 'string' && hidden.has(item.attribute) ? null : item
}
