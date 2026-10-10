import type { FieldDefinition } from '@/types'

/**
 * Depth-first list of every leaf field of a form's field tree, descending
 * through the layout containers (`panel` and `section` hold `fields`,
 * `tab_group` holds `tabs[].fields`).
 */
export function flattenFields(items: readonly unknown[]): FieldDefinition[] {
  const out: FieldDefinition[] = []
  const walk = (list: readonly unknown[]): void => {
    for (const item of list) {
      const f = item as Record<string, unknown>
      if (f.type === 'panel' || f.type === 'section') {
        walk((f.fields as unknown[]) ?? [])
      } else if (f.type === 'tab_group') {
        for (const tab of (f.tabs as { fields?: unknown[] }[]) ?? []) walk(tab.fields ?? [])
      } else {
        out.push(item as FieldDefinition)
      }
    }
  }
  walk(items)
  return out
}
