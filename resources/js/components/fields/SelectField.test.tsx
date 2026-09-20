import { describe, it, expect, vi } from 'vitest'
import { render, fireEvent, screen, waitFor } from '@testing-library/react'
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

describe('SelectFieldInput — searchable options (local)', () => {
  it('renders no filter box by default', () => {
    const { container } = render(
      <SelectFieldInput field={makeField()} value="" onChange={vi.fn()} error={undefined} />,
    )
    fireEvent.click(container.querySelector('.p-dropdown')!)
    expect(document.querySelector('.p-dropdown-filter')).toBeNull()
  })

  it('renders the filter box and narrows the list when searchableOptions is set', async () => {
    const field = makeField({
      searchableOptions: true,
      options: [{ label: 'Claude Opus 5', value: 'claude-opus-5' }, { label: 'GPT-4o', value: 'gpt-4o' }],
    })
    const { container } = render(
      <SelectFieldInput field={field} value="" onChange={vi.fn()} error={undefined} />,
    )
    fireEvent.click(container.querySelector('.p-dropdown')!)

    const filter = document.querySelector('.p-dropdown-filter') as HTMLInputElement
    expect(filter).not.toBeNull()
    fireEvent.change(filter, { target: { value: 'gpt' } })

    // PrimeReact debounces its filter input (filterDelay, 300 ms).
    await waitFor(() => expect(screen.queryByText('Claude Opus 5')).toBeNull())
    expect(screen.getByText('GPT-4o')).toBeTruthy()
  })

  it('matches on the value too, not only the label', async () => {
    const field = makeField({
      searchableOptions: true,
      options: [{ label: 'Anthropic flagship', value: 'claude-opus-5' }, { label: 'OpenAI flagship', value: 'gpt-4o' }],
    })
    const { container } = render(
      <SelectFieldInput field={field} value="" onChange={vi.fn()} error={undefined} />,
    )
    fireEvent.click(container.querySelector('.p-dropdown')!)
    fireEvent.change(document.querySelector('.p-dropdown-filter')!, { target: { value: 'claude' } })

    await waitFor(() => expect(screen.queryByText('OpenAI flagship')).toBeNull())
    expect(screen.getByText('Anthropic flagship')).toBeTruthy()
  })

  it('does not enable the search box when only the column-search flag is set', () => {
    const { container } = render(
      <SelectFieldInput field={makeField({ searchable: true })} value="" onChange={vi.fn()} error={undefined} />,
    )
    fireEvent.click(container.querySelector('.p-dropdown')!)
    expect(document.querySelector('.p-dropdown-filter')).toBeNull()
  })
})

describe('SelectFieldInput — custom values', () => {
  it('renders a read-only label by default', () => {
    const { container } = render(
      <SelectFieldInput field={makeField()} value="active" onChange={vi.fn()} error={undefined} />,
    )
    expect(container.querySelector('input.p-dropdown-label')).toBeNull()
  })

  it('renders an editable input and hands a typed value outside the options to onChange', () => {
    const onChange = vi.fn()
    const { container } = render(
      <SelectFieldInput field={makeField({ allowCustomValues: true })} value="" onChange={onChange} error={undefined} />,
    )
    const input = container.querySelector('input.p-dropdown-label') as HTMLInputElement
    expect(input).not.toBeNull()
    fireEvent.input(input, { target: { value: 'my-custom-model' } })
    expect(onChange).toHaveBeenCalledWith('my-custom-model')
  })

  it('shows a stored value that is not one of the options', () => {
    const { container } = render(
      <SelectFieldInput field={makeField({ allowCustomValues: true })} value="not-an-option" onChange={vi.fn()} error={undefined} />,
    )
    expect((container.querySelector('input.p-dropdown-label') as HTMLInputElement).value).toBe('not-an-option')
  })
})
