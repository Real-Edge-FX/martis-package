import { describe, it, expect } from 'vitest'
import { relatedRecordLabel } from './relatedRecordLabel'

/*
 * Option labels of the relation pickers (BelongsTo, MorphTo, Tag). Relatable
 * rows carry the related resource's index values, so a title attribute shown
 * as a Stack on that index arrives as `{ __martisStack: true, entries }`.
 * BelongsTo read the text out of it; MorphTo and Tag printed
 * "[object Object]" for every option.
 */

const stack = (entries: Array<{ text: string; variant?: string }>) => ({ __martisStack: true, entries, divider: false })

describe('relatedRecordLabel', () => {
  it('uses a plain title attribute', () => {
    expect(relatedRecordLabel({ id: 1, name: 'Ana' }, 'name')).toBe('Ana')
    expect(relatedRecordLabel({ id: 1, code: 42 }, 'code')).toBe('42')
  })

  it('reads the heading text out of a Stack-formatted title attribute', () => {
    const record = { id: 1, name: stack([{ text: 'ana@acme.test' }, { text: 'Ana Silva', variant: 'heading' }]) }

    expect(relatedRecordLabel(record, 'name')).toBe('Ana Silva')
    expect(relatedRecordLabel({ id: 2, name: stack([{ text: 'First line' }]) }, 'name')).toBe('First line')
  })

  it('falls back to the resolved _title for an object it cannot read', () => {
    expect(relatedRecordLabel({ id: 1, name: { icon: null }, _title: 'Ana Silva' }, 'name')).toBe('Ana Silva')
  })

  it('tries common attributes, then the id', () => {
    expect(relatedRecordLabel({ id: 1, title: 'Hello' })).toBe('Hello')
    expect(relatedRecordLabel({ id: 1, email: 'ana@acme.test' })).toBe('ana@acme.test')
    expect(relatedRecordLabel({ id: 9 })).toBe('#9')
  })
})
