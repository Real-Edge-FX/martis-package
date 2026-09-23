import { describe, it, expect } from 'vitest'
import type { DetailItem, FieldDefinition, PanelDefinition, SectionDefinition, TabGroupDefinition } from '@/types'
import { lockImmutableFields } from '@/lib/lockImmutableFields'

/*
 * An update form renders an `immutable()` field read-only: every update
 * endpoint skips it. The descriptors mirror `Field::toArray()`, where an
 * immutable field serialises `immutable: true` next to `readonly: false`.
 */

function field(attribute: string, extra: Record<string, unknown> = {}): FieldDefinition {
  return { attribute, label: attribute, type: 'text', readonly: false, immutable: false, ...extra } as unknown as FieldDefinition
}

describe('lockImmutableFields', () => {
  it('marks an immutable field readonly and leaves the other fields as they are', () => {
    const title = field('title')
    const code = field('code', { immutable: true })
    const locked = field('reference', { immutable: true, readonly: true })

    const [nextTitle, nextCode, nextLocked] = lockImmutableFields([title, code, locked])

    expect(nextTitle).toBe(title)
    expect(nextCode).toEqual({ ...code, readonly: true })
    expect(nextLocked).toBe(locked)
    // The schema's own descriptor is not mutated.
    expect(code.readonly).toBe(false)
  })

  it('locks the immutable fields inside panels, sections and tabs', () => {
    const panel = { type: 'panel', title: 'Identity', fields: [field('code', { immutable: true })] } as unknown as PanelDefinition
    const section = {
      type: 'section',
      title: null,
      fields: [field('reference', { immutable: true }), field('note')],
    } as unknown as SectionDefinition
    const tabs = {
      type: 'tab_group',
      tabs: [
        { title: 'Main', fields: [field('number', { immutable: true })] },
        { title: 'Extra', fields: [{ type: 'panel', title: 'Nested', fields: [field('serial', { immutable: true })] }] },
      ],
    } as unknown as TabGroupDefinition

    const [nextPanel, nextSection, nextTabs] = lockImmutableFields<DetailItem>([panel, section, tabs]) as [
      PanelDefinition,
      SectionDefinition,
      TabGroupDefinition,
    ]

    expect(nextPanel.fields[0]!.readonly).toBe(true)
    expect(nextSection.fields.map((f) => f.readonly)).toEqual([true, false])
    expect((nextTabs.tabs[0]!.fields[0] as FieldDefinition).readonly).toBe(true)
    expect((nextTabs.tabs[1]!.fields[0] as PanelDefinition).fields[0]!.readonly).toBe(true)
  })

  it('returns the same items when no field is immutable', () => {
    const items: DetailItem[] = [
      field('title'),
      { type: 'panel', title: 'Body', fields: [field('body')] } as unknown as PanelDefinition,
    ]

    expect(lockImmutableFields(items)).toBe(items)
  })

  it('locks an immutable Repeater as a whole and leaves the fields of its rows alone', () => {
    // The Repeater writes its rows as one value: the server skips an
    // immutable Repeater on update, but no field inside a row.
    const repeatables = [{ shortName: 'line', fields: [field('sku', { immutable: true })] }]
    const rows = field('lines', { type: 'repeater', repeatables })
    const frozen = field('history', { type: 'repeater', immutable: true, repeatables })

    const [nextRows, nextFrozen] = lockImmutableFields([rows, frozen])

    expect(nextRows).toBe(rows)
    expect(nextFrozen!.readonly).toBe(true)
    expect(nextFrozen!.repeatables).toBe(repeatables)
  })
})
