import { describe, it, expect, vi } from 'vitest'
import { fireEvent, render, screen } from '@testing-library/react'
import type { FieldDefinition } from '@/types'
import { RepeaterFieldInput } from './RepeaterField'
import { registerDefaultFields } from './FieldRenderer'

/*
 * A Repeater row writes only the row fields its form can change: a readonly
 * row field keeps the stored row's value and a new row takes its default,
 * and an immutable one is written on a new row and kept on a stored one. The
 * input rendered an immutable row field editable on every row, so an edit on
 * a row the record stores would be dropped by the save (200, value
 * unchanged), and a new row (added from a template, duplicated or pasted)
 * showed values of readonly fields that the save replaces with their
 * defaults. The Add menu of a Repeater with one row type and row templates
 * listed the templates only, so no blank row could be added.
 *
 * Real field inputs render the rows: a mocked child never reads `readonly`.
 */

registerDefaultFields()

const field = {
  attribute: 'lines', label: 'Lines', type: 'repeater',
  nullable: true, readonly: false, required: false, sortable: false,
  searchable: false, showOnIndex: false, showOnDetail: true, showOnForms: true,
  rules: [], storage: 'json',
  rowTemplates: [{ label: 'Preset', type: 'line', fields: { name: 'Preset', code: 'TPL', slug: 'preset' } }],
  repeatables: [
    {
      shortName: 'line', uniqueKey: 'line', label: 'Line',
      fields: [
        { attribute: 'name', label: 'Name', type: 'text', readonly: false, rules: [] },
        { attribute: 'code', label: 'Code', type: 'text', readonly: true, defaultValue: 'NEW', rules: [] },
        { attribute: 'slug', label: 'Slug', type: 'text', readonly: false, immutable: true, rules: [] },
      ],
    },
  ],
} as unknown as FieldDefinition

const stored = [{ id: 'a', type: 'line', fields: { name: 'First', code: 'C-1', slug: 'first' } }]

type EmittedRow = { id: unknown; fields: Record<string, unknown> }

const lastRows = (onChange: ReturnType<typeof vi.fn>) => onChange.mock.lastCall?.[0] as EmittedRow[]

/** The slug input of each rendered row, in order. */
const slugInputs = (container: HTMLElement) =>
  ([...container.querySelectorAll('input')] as HTMLInputElement[]).filter((_, index) => index % 3 === 2)

describe('RepeaterFieldInput immutable row fields', () => {
  it('renders an immutable row field read-only on a row the record stores, and editable on a new row', () => {
    const onChange = vi.fn()
    const { container, rerender } = render(
      <RepeaterFieldInput field={field} value={stored} onChange={onChange} context="update" />,
    )

    expect((screen.getByDisplayValue('first') as HTMLInputElement).readOnly).toBe(true)
    expect((screen.getByDisplayValue('First') as HTMLInputElement).readOnly).toBe(false)

    fireEvent.click(screen.getByRole('button', { name: 'Add row' }))
    fireEvent.click(screen.getByRole('button', { name: 'Line' }))
    rerender(<RepeaterFieldInput field={field} value={lastRows(onChange)} onChange={onChange} context="update" />)

    const [storedSlug, newSlug] = slugInputs(container)
    expect(storedSlug.readOnly).toBe(true)
    expect(newSlug.readOnly).toBe(false)
  })

  it('locks the rows of the record an update form hands the input after it mounted', () => {
    const onChange = vi.fn()
    const { rerender } = render(<RepeaterFieldInput field={field} value={null} onChange={onChange} context="update" />)

    rerender(<RepeaterFieldInput field={field} value={stored} onChange={onChange} context="update" />)

    expect((screen.getByDisplayValue('first') as HTMLInputElement).readOnly).toBe(true)
  })

  it('keeps an immutable row field editable on every row of a create form', () => {
    render(<RepeaterFieldInput field={field} value={stored} onChange={vi.fn()} context="create" />)

    expect((screen.getByDisplayValue('first') as HTMLInputElement).readOnly).toBe(false)
  })

  it('renders an immutable row field editable on a duplicated row', () => {
    const onChange = vi.fn()
    const { container, rerender } = render(
      <RepeaterFieldInput field={field} value={stored} onChange={onChange} context="update" />,
    )

    fireEvent.click(container.querySelector('[data-pr-tooltip="Duplicate row"]') as HTMLElement)
    rerender(<RepeaterFieldInput field={field} value={lastRows(onChange)} onChange={onChange} context="update" />)

    expect(slugInputs(container).map((input) => input.readOnly)).toEqual([true, false])
  })
})

describe('RepeaterFieldInput new rows and readonly row fields', () => {
  it('offers a blank row of the only row type next to the row templates', () => {
    const onChange = vi.fn()
    render(<RepeaterFieldInput field={field} value={[]} onChange={onChange} context="create" />)

    fireEvent.click(screen.getByRole('button', { name: 'Add row' }))
    fireEvent.click(screen.getByRole('button', { name: 'Line' }))

    expect(lastRows(onChange)[0].fields).toEqual({ name: null, code: 'NEW', slug: null })
  })

  it('gives a duplicated row the default of a readonly field instead of the copied value', () => {
    const onChange = vi.fn()
    const { container } = render(<RepeaterFieldInput field={field} value={stored} onChange={onChange} context="update" />)

    fireEvent.click(container.querySelector('[data-pr-tooltip="Duplicate row"]') as HTMLElement)

    const [original, copy] = lastRows(onChange)
    expect(original.fields).toEqual({ name: 'First', code: 'C-1', slug: 'first' })
    expect(copy.id).not.toBe('a')
    expect(copy.fields).toEqual({ name: 'First', code: 'NEW', slug: 'first' })
  })

  it('takes no value of a readonly field from a row template', () => {
    const onChange = vi.fn()
    render(<RepeaterFieldInput field={field} value={[]} onChange={onChange} context="create" />)

    fireEvent.click(screen.getByRole('button', { name: 'Add row' }))
    fireEvent.click(screen.getByText('Preset'))

    expect(lastRows(onChange)[0].fields).toEqual({ name: 'Preset', code: 'NEW', slug: 'preset' })
  })

  it('takes no value of a readonly field from pasted rows', () => {
    const onChange = vi.fn()
    render(<RepeaterFieldInput field={field} value={[]} onChange={onChange} context="create" />)

    fireEvent.click(screen.getByRole('button', { name: 'Paste rows' }))
    fireEvent.change(document.querySelector('textarea') as HTMLTextAreaElement, { target: { value: 'name,code,slug\nPasted,PASTE,pasted' } })
    fireEvent.click(screen.getByRole('button', { name: 'Import' }))

    expect(lastRows(onChange)[0].fields).toEqual({ name: 'Pasted', code: 'NEW', slug: 'pasted' })
  })
})
