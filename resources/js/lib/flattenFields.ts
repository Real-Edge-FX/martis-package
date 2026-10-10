import type { FieldDefinition } from '@/types'

/**
 * Layout containers (`tab_group` / `section` / `panel`) nest their child
 * fields under different keys: `section`/`panel` hold `fields`, and
 * `tab_group` holds `tabs[].fields` (each tab's `fields` may itself contain a
 * nested `panel`/`section`).
 *
 * Depth-first collect every leaf field, descending through all containers.
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
