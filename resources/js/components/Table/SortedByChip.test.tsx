import { describe, it, expect, vi } from 'vitest'
import { fireEvent, render, screen, within } from '@testing-library/react'
import { SortedByChip } from '@/components/Table/SortedByChip'
import type { SortKey } from '@/lib/multiSort'

const columns = [
  { attribute: 'category', label: 'Category' },
  { attribute: 'position', label: 'Position' },
  { attribute: 'locale', label: 'Language' },
  { attribute: 'name', label: 'Name' },
]
const defaults: SortKey[] = [{ attribute: 'category', direction: 'asc' }, { attribute: 'position', direction: 'asc' }]

function setup(sort: SortKey[], onChange = vi.fn()) {
  render(<SortedByChip sort={sort} defaultSort={defaults} columns={columns} onChange={onChange} />)
  return onChange
}

const current: SortKey[] = [{ attribute: 'locale', direction: 'asc' }, { attribute: 'category', direction: 'desc' }]
const open = () => fireEvent.click(screen.getByRole('button', { name: /Sorted by/ }))

describe('SortedByChip', () => {
  it('hides when the sort equals the default', () => {
    setup(defaults)

    expect(screen.queryByRole('button', { name: /Sorted by/ })).toBeNull()
  })

  it('names the keys with their direction', () => {
    setup(current)

    expect(screen.getByRole('button', { name: /Sorted by/ }).textContent).toContain('Language ↑, Category ↓')
  })

  it('moves a key down and up', () => {
    const onChange = setup(current)
    open()
    const dialog = screen.getByRole('dialog')

    fireEvent.click(within(dialog).getByRole('button', { name: /Language: Move down/ }))
    expect(onChange).toHaveBeenLastCalledWith([current[1], current[0]])

    fireEvent.click(within(dialog).getByRole('button', { name: /Category: Move up/ }))
    expect(onChange).toHaveBeenLastCalledWith([current[1], current[0]])
    expect((within(dialog).getByRole('button', { name: /Language: Move up/ }) as HTMLButtonElement).disabled).toBe(true)
  })

  it('flips a direction', () => {
    const onChange = setup(current)
    open()

    fireEvent.click(within(screen.getByRole('dialog')).getByRole('button', { name: /Language: Change direction/ }))

    expect(onChange).toHaveBeenCalledWith([{ attribute: 'locale', direction: 'desc' }, current[1]])
  })

  it('removes a key', () => {
    const onChange = setup(current)
    open()

    fireEvent.click(within(screen.getByRole('dialog')).getByRole('button', { name: /Category: Remove/ }))

    expect(onChange).toHaveBeenCalledWith([current[0]])
  })

  it('adds a column from the ones left, ascending', () => {
    const onChange = setup(current)
    open()
    const dialog = screen.getByRole('dialog')

    fireEvent.click(within(dialog).getByRole('button', { name: /Add column/ }))
    const select = within(dialog).getByRole('combobox')
    expect(within(select).queryByRole('option', { name: 'Language' })).toBeNull()
    fireEvent.change(select, { target: { value: 'name' } })

    expect(onChange).toHaveBeenCalledWith([...current, { attribute: 'name', direction: 'asc' }])
  })

  it('offers no more columns at the cap', () => {
    setup([
      { attribute: 'locale', direction: 'asc' },
      { attribute: 'category', direction: 'asc' },
      { attribute: 'name', direction: 'asc' },
    ])
    open()

    expect(within(screen.getByRole('dialog')).queryByRole('button', { name: /Add column/ })).toBeNull()
  })
})
