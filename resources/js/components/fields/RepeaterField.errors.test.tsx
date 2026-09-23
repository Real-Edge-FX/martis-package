import { describe, it, expect, vi } from 'vitest'
import { useEffect, useState } from 'react'
import { fireEvent, render, screen, within } from '@testing-library/react'
import type { FieldDefinition } from '@/types'
import { useMartisForm } from '@/hooks/useMartisForm'
import { RepeaterFieldInput } from './RepeaterField'
import { registerDefaultFields } from './FieldRenderer'
import { FieldsForm } from './FieldsForm'
import type { FieldInputProps } from './types'

/*
 * The server validates the fields inside a Repeater's rows and keys each
 * error by its path below the Repeater: `1.fields.name` is the `name` field
 * of row 1. The form handed the Repeater `errors[attribute]` only, and the
 * Repeater read its row errors from that string as if it were an object, so
 * no row error ever showed, and neither did the Repeater's own error. The
 * Repeater now gets the errors inside its value (`nestedErrors`) and shows
 * each under the row field it belongs to.
 */

vi.mock('@/hooks/useDependsOnSync', () => ({
  useDependsOnSync: () => new Map(),
}))

registerDefaultFields()

function textField(attribute: string, label: string): Record<string, unknown> {
  return { attribute, label, type: 'text', nullable: true, readonly: false, required: false, rules: [] }
}

function makeField(extra: Record<string, unknown> = {}): FieldDefinition {
  return {
    attribute: 'lines', label: 'Lines', type: 'repeater',
    nullable: true, readonly: false, required: false, sortable: false,
    searchable: false, showOnIndex: false, showOnDetail: true, showOnForms: true,
    rules: [], storage: 'json', reorderable: true, collapsible: true,
    repeatables: [{ shortName: 'line', uniqueKey: 'line', label: 'Line', fields: [textField('name', 'Name')] }],
    ...extra,
  } as unknown as FieldDefinition
}

const stored = [
  { id: 'a', type: 'line', fields: { name: 'First' } },
  { id: 'b', type: 'line', fields: { name: 'Second' } },
]

const required = 'The Name field is required.'

const rowsOf = (container: HTMLElement) => [...container.querySelectorAll('[draggable]')] as HTMLElement[]

const withTooltip = (element: HTMLElement, tooltip: string) =>
  [...element.querySelectorAll(`[data-pr-tooltip="${tooltip}"]`)] as HTMLElement[]

/** Keeps the rows the Repeater emits, as a real form does. */
function Form({ error, nestedErrors, field = makeField() }: Pick<FieldInputProps, 'error' | 'nestedErrors'> & { field?: FieldDefinition }) {
  const [value, setValue] = useState<unknown>(stored)
  return <RepeaterFieldInput field={field} value={value} onChange={setValue} error={error} nestedErrors={nestedErrors} />
}

describe('RepeaterFieldInput server errors', () => {
  it('shows a row field error under that field, in the row it belongs to', () => {
    const { container } = render(<Form nestedErrors={{ '1.fields.name': required }} />)
    const [first, second] = rowsOf(container)

    expect(within(second).getByText(required)).toBeTruthy()
    expect(within(first).queryByText(required)).toBeNull()
    expect(within(second).getByDisplayValue('Second').className).toContain('p-invalid')
    expect(within(first).getByDisplayValue('First').className).not.toContain('p-invalid')
  })

  it('shows the error of the Repeater itself', () => {
    render(<Form error="The Lines field must be an array." />)

    expect(screen.getByText('The Lines field must be an array.')).toBeTruthy()
  })

  it('shows an error of the row itself, such as a row type the server rejected, on that row', () => {
    const { container } = render(<Form nestedErrors={{ '0.type': 'The selected Row type is invalid.' }} />)

    expect(within(rowsOf(container)[0]).getByText('The selected Row type is invalid.')).toBeTruthy()
    expect(within(rowsOf(container)[1]).queryByText('The selected Row type is invalid.')).toBeNull()
  })

  it('keeps an error on its row when a row above it is removed', () => {
    const { container } = render(<Form nestedErrors={{ '1.fields.name': required }} />)

    fireEvent.click(withTooltip(rowsOf(container)[0], 'Remove')[0])

    const rows = rowsOf(container)
    expect(rows).toHaveLength(1)
    expect(within(rows[0]).getByDisplayValue('Second')).toBeTruthy()
    expect(within(rows[0]).getByText(required)).toBeTruthy()
  })

  it('keeps an error on its row when the rows are reordered', () => {
    const { container } = render(<Form nestedErrors={{ '1.fields.name': required }} />)
    const [first, second] = rowsOf(container)

    fireEvent.dragStart(second, { dataTransfer: { effectAllowed: 'none' } })
    fireEvent.dragOver(first, { dataTransfer: { dropEffect: 'none' } })
    fireEvent.drop(first, { dataTransfer: {} })

    const rows = rowsOf(container)
    expect(within(rows[0]).getByDisplayValue('Second')).toBeTruthy()
    expect(within(rows[0]).getByText(required)).toBeTruthy()
    expect(within(rows[1]).queryByText(required)).toBeNull()
  })

  it('clears a row field error when that field changes and keeps the errors of the other rows', () => {
    const { container } = render(<Form nestedErrors={{ '0.fields.name': 'That name is taken.', '1.fields.name': required }} />)

    fireEvent.change(within(rowsOf(container)[1]).getByDisplayValue('Second'), { target: { value: 'Fixed' } })

    expect(screen.queryByText(required)).toBeNull()
    expect(within(rowsOf(container)[0]).getByText('That name is taken.')).toBeTruthy()
  })

  it('shows the errors of a later save and opens the collapsed rows that have one', () => {
    const field = makeField({ collapsedByDefault: true })
    const { container, rerender } = render(<RepeaterFieldInput field={field} value={stored} onChange={() => {}} />)
    expect(screen.queryByDisplayValue('Second')).toBeNull()

    rerender(<RepeaterFieldInput field={field} value={stored} onChange={() => {}} nestedErrors={{ '1.fields.name': required }} />)

    expect(within(rowsOf(container)[1]).getByText(required)).toBeTruthy()
    expect(screen.getByDisplayValue('Second')).toBeTruthy()
    expect(screen.queryByDisplayValue('First')).toBeNull()
  })

  it('passes the errors inside a row field down to it, so a Repeater inside a row shows its own row errors', () => {
    const links = { ...makeField(), attribute: 'links', label: 'Links', repeatables: [{ shortName: 'link', uniqueKey: 'link', label: 'Link', fields: [textField('url', 'URL')] }] }
    const field = makeField({ repeatables: [{ shortName: 'menu', uniqueKey: 'menu', label: 'Menu', fields: [links] }] })
    const value = [{ id: 'm', type: 'menu', fields: { links: [{ id: 'l1', type: 'link', fields: { url: '/' } }, { id: 'l2', type: 'link', fields: { url: '' } }] } }]

    render(<RepeaterFieldInput field={field} value={value} onChange={() => {}} nestedErrors={{ '0.fields.links.1.fields.url': 'The URL field is required.' }} />)

    const innerRows = [...document.querySelectorAll('[draggable] [draggable]')] as HTMLElement[]
    expect(innerRows).toHaveLength(2)
    expect(within(innerRows[1]).getByText('The URL field is required.')).toBeTruthy()
    expect(within(innerRows[0]).queryByText('The URL field is required.')).toBeNull()
  })
})

describe('Repeater errors through the shared form', () => {
  function Harness({ errors, fields = [makeField()] }: { errors: Record<string, string>; fields?: FieldDefinition[] }) {
    const form = useMartisForm({ fields, initialValues: { lines: stored }, context: 'update' })
    const { setErrors } = form
    useEffect(() => {
      setErrors(errors)
    }, [errors, setErrors])
    return <FieldsForm form={form} context="update" />
  }

  const errors = { lines: 'The Lines field must have at least 3 items.', 'lines.1.fields.name': required }

  it('gives the Repeater the errors of its rows from the form error map', () => {
    const { container } = render(<Harness errors={errors} />)

    expect(within(rowsOf(container)[1]).getByText(required)).toBeTruthy()
    expect(within(rowsOf(container)[0]).queryByText(required)).toBeNull()
    expect(screen.getByText('The Lines field must have at least 3 items.')).toBeTruthy()
  })

  it.each([
    ['section', { type: 'section', title: 'Content', columns: 12, fields: [makeField()] }],
    ['panel', { type: 'panel', title: 'Content', fields: [makeField()], collapsible: false, collapsedByDefault: false, limit: null }],
    ['tab', { type: 'tab_group', tabs: [{ title: 'Content', fields: [makeField()] }] }],
  ])('gives a Repeater inside a %s the errors of its rows', (_label, container) => {
    const { container: root } = render(<Harness errors={errors} fields={[container as unknown as FieldDefinition]} />)

    expect(within(rowsOf(root)[1]).getByText(required)).toBeTruthy()
    expect(within(rowsOf(root)[0]).queryByText(required)).toBeNull()
    expect(screen.getByText('The Lines field must have at least 3 items.')).toBeTruthy()
  })
})
