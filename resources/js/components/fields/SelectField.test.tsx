import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, fireEvent, screen, waitFor } from '@testing-library/react'
import type { FieldDefinition } from '@/types'

const apiGetMock = vi.fn()

vi.mock('@/lib/api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/api')>()
  return { ...actual, api: { ...actual.api, get: (...args: unknown[]) => apiGetMock(...args) } }
})

vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (_k: string, o?: { defaultValue?: string }) => o?.defaultValue ?? _k }),
}))

// Imported AFTER the mocks are registered.
const { SelectFieldInput } = await import('./SelectField')

beforeEach(() => {
  apiGetMock.mockReset()
  apiGetMock.mockResolvedValue({ data: { options: [] } })
})

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

describe('SelectFieldInput — remote option search', () => {
  const remoteField = () => makeField({
    attribute: 'model', searchableOptions: true, remoteOptionsSearch: true,
    options: [{ label: 'gpt-4o', value: 'gpt-4o' }],
  })

  it('falls back to local filtering when the form has no scope (no request is made)', () => {
    const { container } = render(
      <SelectFieldInput field={remoteField()} value="" onChange={vi.fn()} error={undefined} />,
    )
    fireEvent.click(container.querySelector('.p-dropdown')!)
    expect(apiGetMock).not.toHaveBeenCalled()
    expect(document.querySelector('.p-dropdown-filter')).not.toBeNull()
    expect(screen.queryByTestId('select-remote-search-model')).toBeNull()
  })

  it('asks the Resource endpoint on open and renders the returned options', async () => {
    apiGetMock.mockResolvedValue({ data: { options: [{ label: 'Claude Opus 5', value: 'claude-opus-5' }] } })
    const { container } = render(
      <SelectFieldInput field={remoteField()} value="" onChange={vi.fn()} error={undefined} resourceKey="clients" context="update" />,
    )
    fireEvent.click(container.querySelector('.p-dropdown')!)

    await waitFor(() => expect(apiGetMock).toHaveBeenCalledTimes(1))
    expect(apiGetMock.mock.calls[0][0]).toBe('/api/resources/clients/fields/model/options?context=update&search=')
    expect(await screen.findByText('Claude Opus 5')).toBeTruthy()
  })

  it('sends the record id along in an update form', async () => {
    const { container } = render(
      <SelectFieldInput field={remoteField()} value="" onChange={vi.fn()} error={undefined} resourceKey="clients" context="update" recordId={7} />,
    )
    fireEvent.click(container.querySelector('.p-dropdown')!)

    await waitFor(() => expect(apiGetMock).toHaveBeenCalledTimes(1))
    expect(apiGetMock.mock.calls[0][0]).toBe('/api/resources/clients/fields/model/options?context=update&id=7&search=')
  })

  it('asks the Tool endpoint when the form is scoped to a Tool', async () => {
    const { container } = render(
      <SelectFieldInput field={remoteField()} value="" onChange={vi.fn()} error={undefined} toolKey="settings" />,
    )
    fireEvent.click(container.querySelector('.p-dropdown')!)

    await waitFor(() => expect(apiGetMock).toHaveBeenCalledTimes(1))
    expect(apiGetMock.mock.calls[0][0]).toBe('/api/tools/settings/fields/model/options?search=')
  })

  it('sends the typed term through its own search box', async () => {
    const { container } = render(
      <SelectFieldInput field={remoteField()} value="" onChange={vi.fn()} error={undefined} toolKey="settings" />,
    )
    fireEvent.click(container.querySelector('.p-dropdown')!)
    await waitFor(() => expect(apiGetMock).toHaveBeenCalledTimes(1))

    fireEvent.change(screen.getByTestId('select-remote-search-model'), { target: { value: 'claude' } })

    await waitFor(() => expect(apiGetMock).toHaveBeenCalledTimes(2))
    expect(apiGetMock.mock.calls[1][0]).toBe('/api/tools/settings/fields/model/options?search=claude')
  })

  it('does not hide a server match whose label and value do not contain the term', async () => {
    // The server decides what matches (accent-insensitive, fuzzy, by a
    // hidden column). PrimeReact's local filter must stay out of the way.
    apiGetMock.mockResolvedValue({ data: { options: [{ label: 'João', value: 'joao-id' }] } })
    const { container } = render(
      <SelectFieldInput field={remoteField()} value="" onChange={vi.fn()} error={undefined} toolKey="settings" />,
    )
    fireEvent.click(container.querySelector('.p-dropdown')!)
    await waitFor(() => expect(apiGetMock).toHaveBeenCalledTimes(1))
    fireEvent.change(screen.getByTestId('select-remote-search-model'), { target: { value: 'zzz' } })
    await waitFor(() => expect(apiGetMock).toHaveBeenCalledTimes(2))

    expect(await screen.findByText('João')).toBeTruthy()
  })

  it('shows a stored value that is not in the initial list instead of the placeholder', () => {
    const { container } = render(
      <SelectFieldInput field={remoteField()} value="claude-opus-5" onChange={vi.fn()} error={undefined} toolKey="settings" />,
    )
    expect(container.querySelector('.p-dropdown-label')?.textContent).toBe('claude-opus-5')
  })

  it('keeps the clear icon for a stored value even when the initial list is empty', () => {
    // PrimeReact drops the clear icon when `options` is empty; the closed
    // control must still let the user clear a value the list does not carry.
    const field = makeField({ attribute: 'model', remoteOptionsSearch: true, options: [] })
    const { container } = render(
      <SelectFieldInput field={field} value="claude-opus-5" onChange={vi.fn()} error={undefined} toolKey="settings" />,
    )
    expect(container.querySelector('.p-dropdown-clear-icon')).not.toBeNull()
  })

  it('shows the load-error message in the panel when the request fails', async () => {
    apiGetMock.mockRejectedValue(new Error('boom'))
    const { container } = render(
      <SelectFieldInput field={remoteField()} value="" onChange={vi.fn()} error={undefined} toolKey="settings" />,
    )
    fireEvent.click(container.querySelector('.p-dropdown')!)

    expect(await screen.findByText('options_load_error')).toBeTruthy()
  })
})

describe('SelectFieldInput — option groups (v2.0.0)', () => {
  const groupedField = () => makeField({
    attribute: 'size',
    options: [
      { label: 'Small', value: 'MS', group: 'Men Sizes' },
      { label: 'Medium', value: 'MM', group: 'Men Sizes' },
      { label: 'Small', value: 'WS', group: 'Women Sizes' },
    ],
  })

  it('renders a heading per group, in the order the groups first appear', () => {
    const { container } = render(
      <SelectFieldInput field={groupedField()} value="" onChange={vi.fn()} error={undefined} />,
    )
    fireEvent.click(container.querySelector('.p-dropdown')!)

    const headings = Array.from(document.querySelectorAll('.p-dropdown-item-group')).map((h) => h.textContent)
    expect(headings).toEqual(['Men Sizes', 'Women Sizes'])
    expect(document.querySelectorAll('.p-dropdown-item')).toHaveLength(3)
  })

  it('hands the value of an option inside a group to onChange', () => {
    const onChange = vi.fn()
    const { container } = render(
      <SelectFieldInput field={groupedField()} value="" onChange={onChange} error={undefined} />,
    )
    fireEvent.click(container.querySelector('.p-dropdown')!)
    fireEvent.click(document.querySelectorAll('.p-dropdown-item')[2])

    expect(onChange).toHaveBeenCalledWith('WS')
  })

  it('keeps the list flat when no option names a group', () => {
    const { container } = render(
      <SelectFieldInput field={makeField()} value="" onChange={vi.fn()} error={undefined} />,
    )
    fireEvent.click(container.querySelector('.p-dropdown')!)

    expect(document.querySelector('.p-dropdown-item-group')).toBeNull()
    expect(screen.getByText('Active')).toBeTruthy()
  })

  it('puts ungrouped options in a leading group with an empty heading', () => {
    const field = makeField({
      attribute: 'size',
      options: [
        { label: 'One size', value: 'OS' },
        { label: 'Small', value: 'MS', group: 'Men Sizes' },
      ],
    })
    const { container } = render(<SelectFieldInput field={field} value="" onChange={vi.fn()} error={undefined} />)
    fireEvent.click(container.querySelector('.p-dropdown')!)

    const headings = Array.from(document.querySelectorAll('.p-dropdown-item-group-label')).map((h) => h.textContent)
    expect(headings).toEqual(['', 'Men Sizes'])
    expect(screen.getByText('One size')).toBeTruthy()
  })

  it('groups the options the server returns', async () => {
    apiGetMock.mockResolvedValue({ data: { options: [
      { label: 'Small', value: 'MS', group: 'Men Sizes' },
      { label: 'Small', value: 'WS', group: 'Women Sizes' },
    ] } })
    const field = makeField({ attribute: 'size', searchableOptions: true, remoteOptionsSearch: true, options: [] })
    const { container } = render(
      <SelectFieldInput field={field} value="" onChange={vi.fn()} error={undefined} resourceKey="clients" />,
    )
    fireEvent.click(container.querySelector('.p-dropdown')!)

    await waitFor(() => expect(document.querySelectorAll('.p-dropdown-item-group')).toHaveLength(2))
  })
})
