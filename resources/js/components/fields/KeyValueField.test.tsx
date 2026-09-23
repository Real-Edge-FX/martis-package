import { describe, it, expect, vi } from 'vitest'
import { render, fireEvent, screen } from '@testing-library/react'
import type { FieldDefinition } from '@/types'
import { KeyValueFieldInput } from './KeyValueField'

/*
 * KeyValue row controls. `disableEditingKeys()` + `disableAddingRows()`
 * present a fixed set of keys, but the per-row delete button stayed live, so
 * an operator could drop a row of a "fixed" map and the save cleared it.
 * `disableDeletingRows()` (Nova parity) removes the button and makes the
 * delete handler a no-op; without it the button keeps working exactly as
 * before.
 */

function makeField(overrides: Partial<FieldDefinition> & Record<string, unknown> = {}): FieldDefinition {
  return {
    attribute: 'opening_hours', label: 'Opening hours', type: 'key_value',
    nullable: true, readonly: false, required: false, sortable: false,
    searchable: false, showOnIndex: false, showOnDetail: true, showOnForms: true,
    rules: [],
    ...overrides,
  } as FieldDefinition
}

const rows = [
  { key: 'mon', value: '09:00-18:00' },
  { key: 'tue', value: '09:00-18:00' },
]

describe('KeyValueFieldInput value from outside', () => {
  it('adopts a value handed in after mount (the edit form hydrating the stored rows)', () => {
    const { container, rerender } = render(
      <KeyValueFieldInput field={makeField()} value={undefined} onChange={() => {}} />,
    )
    expect(container.querySelectorAll('input')).toHaveLength(0)

    rerender(<KeyValueFieldInput field={makeField()} value={rows} onChange={() => {}} />)

    const inputs = [...container.querySelectorAll('input')].map((input) => input.value)
    expect(inputs).toEqual(['mon', '09:00-18:00', 'tue', '09:00-18:00'])
  })

  it('keeps its rows when the form hands back the value it just emitted', () => {
    let current: unknown = rows
    const onChange = vi.fn((next: unknown) => {
      current = next
    })
    const { container, rerender } = render(
      <KeyValueFieldInput field={makeField()} value={current} onChange={onChange} />,
    )

    fireEvent.click(screen.getByRole('button', { name: /add row/i }))
    rerender(<KeyValueFieldInput field={makeField()} value={current} onChange={onChange} />)

    expect(container.querySelectorAll('input')).toHaveLength(6)
    expect(onChange).toHaveBeenLastCalledWith([...rows, { key: '', value: '' }])
  })
})

describe('KeyValueFieldInput row deletion', () => {
  it('renders a delete button per row and removes the row by default', () => {
    const onChange = vi.fn()
    render(<KeyValueFieldInput field={makeField()} value={rows} onChange={onChange} />)

    const buttons = screen.getAllByRole('button', { name: 'Remove row' })
    expect(buttons).toHaveLength(2)

    fireEvent.click(buttons[0])
    expect(onChange).toHaveBeenCalledWith([{ key: 'tue', value: '09:00-18:00' }])
  })

  it('keeps the delete button when only key editing and adding are disabled', () => {
    render(
      <KeyValueFieldInput
        field={makeField({ editingKeysDisabled: true, addingRowsDisabled: true })}
        value={rows}
        onChange={() => {}}
      />,
    )

    expect(screen.getAllByRole('button', { name: 'Remove row' })).toHaveLength(2)
    expect(screen.queryByRole('button', { name: /add row/i })).toBeNull()
  })

  it('renders no delete button when deletingRowsDisabled is set, and keeps values editable', () => {
    const onChange = vi.fn()
    const { container } = render(
      <KeyValueFieldInput
        field={makeField({ editingKeysDisabled: true, addingRowsDisabled: true, deletingRowsDisabled: true })}
        value={rows}
        onChange={onChange}
      />,
    )

    expect(screen.queryByRole('button', { name: 'Remove row' })).toBeNull()
    expect(container.querySelector('[data-pr-tooltip]')).toBeNull()

    const inputs = container.querySelectorAll('input')
    expect(inputs).toHaveLength(4)
    fireEvent.change(inputs[1], { target: { value: 'closed' } })
    expect(onChange).toHaveBeenCalledWith([
      { key: 'mon', value: 'closed' },
      { key: 'tue', value: '09:00-18:00' },
    ])
  })

  it('drops the trailing header spacer when rows carry no delete button', () => {
    const { container: fixed } = render(
      <KeyValueFieldInput field={makeField({ deletingRowsDisabled: true })} value={rows} onChange={() => {}} />,
    )
    const { container: open } = render(
      <KeyValueFieldInput field={makeField()} value={rows} onChange={() => {}} />,
    )

    const headerCells = (root: HTMLElement) => root.firstElementChild?.firstElementChild?.children.length
    expect(headerCells(fixed)).toBe(2)
    expect(headerCells(open)).toBe(3)
  })
})
