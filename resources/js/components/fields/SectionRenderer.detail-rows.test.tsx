import { describe, it, expect } from 'vitest'
import { render } from '@testing-library/react'
import type { FieldDefinition, SectionDefinition } from '@/types'
import { SectionDisplay } from '@/components/fields/SectionRenderer'
import { registerDefaultFields } from '@/components/fields/FieldRenderer'

registerDefaultFields()

function field(attribute: string, label: string, extra: Record<string, unknown> = {}): FieldDefinition {
  return {
    attribute, label, type: 'text',
    nullable: true, readonly: false, required: false, sortable: false, searchable: false,
    showOnIndex: true, showOnDetail: true, showOnForms: true, rules: [], reserved: [],
    ...extra,
  } as unknown as FieldDefinition
}

function renderSection(fields: FieldDefinition[]) {
  const section = { title: 'Sheet', columns: 4, fields } as unknown as SectionDefinition
  return render(<SectionDisplay section={section} values={{ a: 'A', b: 'B', c: 'C' }} />)
}

describe('SectionDisplay rows', () => {
  it('carries data-field and data-type on every row', () => {
    const { container } = renderSection([field('a', 'Alpha', { component: 'email' })])
    const row = container.querySelector('[data-field="a"]')!
    expect(row.getAttribute('data-type')).toBe('email')
  })

  it('fullWidth takes the whole row whatever colSpan says', () => {
    const { container } = renderSection([
      field('a', 'Alpha', { colSpan: 1 }),
      field('b', 'Beta', { colSpan: 1, fullWidth: true }),
    ])
    const normal = container.querySelector('[data-field="a"]') as HTMLElement
    const wide = container.querySelector('[data-field="b"]') as HTMLElement
    expect(normal.classList.contains('is-full-width')).toBe(false)
    expect(normal.style.getPropertyValue('--martis-field-span')).toBe('1')
    expect(wide.classList.contains('is-full-width')).toBe(true)
    expect(wide.style.getPropertyValue('--martis-field-span')).toBe('4')
    expect(wide.style.getPropertyValue('--martis-field-span-lg')).toBe('4')
  })

  it('hideLabelOnDetail keeps a screen-reader label and implies a full row', () => {
    const { container } = renderSection([field('c', 'Gamma', { colSpan: 1, hideLabelOnDetail: true })])
    const row = container.querySelector('[data-field="c"]') as HTMLElement
    expect(row.classList.contains('is-label-hidden')).toBe(true)
    expect(row.classList.contains('is-full-width')).toBe(true)
    const dt = row.querySelector('dt')!
    expect(dt.classList.contains('sr-only')).toBe(true)
    expect(dt.textContent).toContain('Gamma')
  })
})
