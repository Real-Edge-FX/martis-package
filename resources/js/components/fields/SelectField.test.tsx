import { describe, it, expect, vi } from 'vitest'
import { render } from '@testing-library/react'
import { SelectFieldInput } from './SelectField'
import type { FieldDefinition } from '@/types'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (_k: string, o?: { defaultValue?: string }) => o?.defaultValue ?? _k }),
}))

function makeField(overrides: Partial<FieldDefinition> = {}): FieldDefinition {
  return {
    attribute: 'status', label: 'Status', type: 'select',
    nullable: true, readonly: false, required: false, sortable: false,
    searchable: false, showOnIndex: true, showOnDetail: true, showOnForms: true,
    rules: [], options: [{ label: 'Active', value: 'active' }],
    ...overrides,
  } as FieldDefinition
}

describe('SelectFieldInput — clear icon + filter variant', () => {
  it('hides the clear (X) icon on an empty nullable select', () => {
    // Empty value must pass null to PrimeReact so its `value != null` guard
    // hides the clear icon — there is nothing to clear.
    const { container } = render(
      <SelectFieldInput field={makeField()} value="" onChange={vi.fn()} error={undefined} />,
    )
    expect(container.querySelector('.p-dropdown-clear-icon')).toBeNull()
  })

  it('shows the clear (X) icon when a value is selected', () => {
    const { container } = render(
      <SelectFieldInput field={makeField()} value="active" onChange={vi.fn()} error={undefined} />,
    )
    expect(container.querySelector('.p-dropdown-clear-icon')).not.toBeNull()
  })

  it('applies martis-filter-dropdown when field.variant is "filter"', () => {
    const { container } = render(
      <SelectFieldInput field={makeField({ variant: 'filter' })} value="" onChange={vi.fn()} error={undefined} />,
    )
    expect(container.querySelector('.p-dropdown.martis-filter-dropdown')).not.toBeNull()
  })

  it('treats a real empty-string option as selected (not empty)', () => {
    // When an option's value is literally '', selecting it must keep the
    // value (show the clear X), not collapse to the placeholder.
    const field = makeField({ options: [{ label: 'None', value: '' }, { label: 'Active', value: 'active' }] })
    const { container } = render(
      <SelectFieldInput field={field} value="" onChange={vi.fn()} error={undefined} />,
    )
    expect(container.querySelector('.p-dropdown-clear-icon')).not.toBeNull()
  })

  it('forwards field.className to the Dropdown root', () => {
    const { container } = render(
      <SelectFieldInput field={makeField({ className: 'my-custom' })} value="" onChange={vi.fn()} error={undefined} />,
    )
    expect(container.querySelector('.p-dropdown.my-custom')).not.toBeNull()
  })
})
