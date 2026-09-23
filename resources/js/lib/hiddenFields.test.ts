import { describe, it, expect } from 'vitest'
import { renderHook } from '@testing-library/react'
import type { DetailItem, FieldDefinition, PanelDefinition, SectionDefinition, TabGroupDefinition } from '@/types'
import { hiddenAttributes, isHiddenOn, useHiddenAttributes, withoutHiddenFields } from '@/lib/hiddenFields'

/*
 * A record lists under `_hidden` the attributes of the fields
 * `canSeeForModel()` hides for it (v1.38.0). The schema describes the
 * resource, so the pages drop those fields from the field lists they render
 * against the record.
 */

function field(attribute: string): FieldDefinition {
  return { attribute, label: attribute, type: 'text' } as unknown as FieldDefinition
}

describe('hiddenAttributes / isHiddenOn', () => {
  it('reads the attributes a record hides', () => {
    const record = { id: 1, _hidden: ['salary', 'bonus'] }

    expect([...hiddenAttributes(record)]).toEqual(['salary', 'bonus'])
    expect(isHiddenOn(record, 'salary')).toBe(true)
    expect(isHiddenOn(record, 'name')).toBe(false)
  })

  it('reads nothing from a record that hides no field, or from no record', () => {
    const shown = { id: 1, salary: '100' }
    const malformed = { id: 1, _hidden: 'salary' }

    expect(hiddenAttributes(shown).size).toBe(0)
    expect(hiddenAttributes(null).size).toBe(0)
    expect(hiddenAttributes(undefined).size).toBe(0)
    expect(hiddenAttributes(malformed).size).toBe(0)
    expect(isHiddenOn(null, 'salary')).toBe(false)
  })
})

describe('useHiddenAttributes', () => {
  it('keeps the same set while the record hides the same fields', () => {
    const { result, rerender } = renderHook(({ record }) => useHiddenAttributes(record), {
      initialProps: { record: { id: 1, _hidden: ['salary'] } as { id: number; _hidden?: string[] } },
    })
    const first = result.current

    rerender({ record: { id: 1, _hidden: ['salary'] } })
    expect(result.current).toBe(first)

    rerender({ record: { id: 1, _hidden: ['salary', 'bonus'] } })
    expect([...result.current]).toEqual(['salary', 'bonus'])

    rerender({ record: { id: 1 } })
    expect(result.current.size).toBe(0)
  })
})

describe('withoutHiddenFields', () => {
  it('leaves the hidden fields out and keeps the others', () => {
    const name = field('name')
    const salary = field('salary')

    expect(withoutHiddenFields([name, salary], new Set(['salary']))).toEqual([name])
  })

  it('returns the list itself when nothing is hidden', () => {
    const items = [field('name'), field('salary')]

    expect(withoutHiddenFields(items, new Set())).toBe(items)
    expect(withoutHiddenFields(items, new Set(['bonus']))).toBe(items)
  })

  it('walks panels, sections and tabs, and leaves out a container left without fields', () => {
    const pay = { type: 'panel', title: 'Pay', fields: [field('salary')] } as unknown as PanelDefinition
    const career = { type: 'section', title: 'Career', fields: [field('grade'), field('bonus')] } as unknown as SectionDefinition
    const inner = { type: 'panel', title: 'Inner', fields: [field('bonus_note'), field('level')] } as unknown as PanelDefinition
    const tabs = {
      type: 'tab_group',
      tabs: [
        { title: 'Main', fields: [field('note'), inner] },
        { title: 'Secret', fields: [field('salary_history')] },
      ],
    } as unknown as TabGroupDefinition
    const hidden = new Set(['salary', 'bonus', 'bonus_note', 'salary_history'])

    const [kept, ...rest] = withoutHiddenFields<DetailItem>([pay, career, tabs], hidden)

    expect(rest).toHaveLength(1)
    expect(kept).toEqual({ ...career, fields: [field('grade')] })
    const [group] = rest as TabGroupDefinition[]
    expect(group.tabs.map((tab) => tab.title)).toEqual(['Main'])
    expect(group.tabs[0].fields).toEqual([field('note'), { ...inner, fields: [field('level')] }])
    // The schema's own descriptors are not mutated.
    expect(career.fields).toHaveLength(2)
    expect(tabs.tabs).toHaveLength(2)
  })

  it('keeps the identity of a container with nothing to leave out', () => {
    const pay = { type: 'panel', title: 'Pay', fields: [field('grade')] } as unknown as PanelDefinition
    const salary = field('salary')

    const [kept] = withoutHiddenFields<DetailItem>([pay, salary], new Set(['salary']))

    expect(kept).toBe(pay)
  })
})
