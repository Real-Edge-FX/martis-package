import { describe, it, expect, vi, afterEach } from 'vitest'
import { useEffect } from 'react'
import { fireEvent, render, screen } from '@testing-library/react'
import type { FieldDefinition } from '@/types'
import { componentRegistry } from '@/lib/componentRegistry'
import { RepeaterFieldInput } from './RepeaterField'
import { registerDefaultFields } from './FieldRenderer'
import type { FieldInputProps } from './types'

/*
 * `Repeater::fill()` skips a `readonly()` Repeater, and an `immutable()` one
 * on update, which the update forms hand to the input as `readonly`. The
 * input never read `field.readonly`: add, remove, duplicate, reorder, bulk
 * paste, the row templates and every row field stayed live, so the form took
 * a change the save then dropped (200, rows unchanged). A readonly Repeater
 * shows its rows (collapse toggles included) with read-only row fields and
 * no control that changes the rows; an editable one works as before.
 *
 * Real field inputs render the rows: a mocked child never reads `readonly`.
 */

registerDefaultFields()

function makeField(readonly: boolean, extra: Record<string, unknown> = {}): FieldDefinition {
  return {
    attribute: 'lines', label: 'Lines', type: 'repeater',
    nullable: true, readonly, required: false, sortable: false,
    searchable: false, showOnIndex: false, showOnDetail: true, showOnForms: true,
    rules: [], storage: 'json', reorderable: true, collapsible: true,
    repeatables: [
      {
        shortName: 'line', uniqueKey: 'line', label: 'Line',
        fields: [{ attribute: 'name', label: 'Name', type: 'text', nullable: true, readonly: false, rules: [] }],
      },
    ],
    ...extra,
  } as unknown as FieldDefinition
}

const stored = [
  { id: 'a', type: 'line', fields: { name: 'First' } },
  { id: 'b', type: 'line', fields: { name: 'Second' } },
]

const templates = { rowTemplates: [{ label: 'Preset', type: 'line', fields: { name: 'Preset' } }] }

function renderRepeater(readonly: boolean, extra: Record<string, unknown> = {}, value: unknown = stored) {
  const onChange = vi.fn()
  const utils = render(<RepeaterFieldInput field={makeField(readonly, extra)} value={value} onChange={onChange} />)
  return { ...utils, onChange }
}

const withTooltip = (container: HTMLElement, tooltip: string) =>
  [...container.querySelectorAll(`[data-pr-tooltip="${tooltip}"]`)] as HTMLElement[]

/** Drags the row at `from` onto the row at `to`. */
function dragRow(container: HTMLElement, from: number, to: number) {
  const rows = [...container.querySelectorAll('[draggable]')] as HTMLElement[]
  fireEvent.dragStart(rows[from], { dataTransfer: { effectAllowed: 'none' } })
  fireEvent.dragOver(rows[to], { dataTransfer: { dropEffect: 'none' } })
  fireEvent.drop(rows[to], { dataTransfer: {} })
}

const lastRows = (onChange: ReturnType<typeof vi.fn>) =>
  (onChange.mock.lastCall?.[0] as Array<{ id: unknown; fields: Record<string, unknown> }>).map((row) => row.fields.name)

// A row input that normalises its value while it mounts and never reads
// `readonly`, as an input an app registers for its own field type may do.
function NormalisingInput({ value, onChange }: FieldInputProps) {
  useEffect(() => {
    if (value !== 'normalised') onChange('normalised')
  }, [value, onChange])
  return <span>{String(value)}</span>
}

afterEach(() => {
  componentRegistry.unregister('field:input:normalising')
})

describe('RepeaterFieldInput readonly', () => {
  it('shows the rows with no control that adds, pastes, duplicates, removes or reorders them', () => {
    const { container } = renderRepeater(true)

    expect(screen.getByDisplayValue('First')).toBeTruthy()
    expect(screen.getByDisplayValue('Second')).toBeTruthy()
    expect(withTooltip(container, 'Collapse')).toHaveLength(2)

    expect(screen.queryByRole('button', { name: 'Add Line' })).toBeNull()
    expect(screen.queryByRole('button', { name: 'Paste rows' })).toBeNull()
    expect(withTooltip(container, 'Duplicate row')).toHaveLength(0)
    expect(withTooltip(container, 'Remove')).toHaveLength(0)
    expect(withTooltip(container, 'Reorder')).toHaveLength(0)
    expect(container.querySelectorAll('[draggable="true"]')).toHaveLength(0)
  })

  it('offers no add menu, so no row template can be added', () => {
    renderRepeater(true, templates)

    expect(screen.queryByRole('button', { name: 'Add row' })).toBeNull()
    expect(screen.queryByText('Preset')).toBeNull()
  })

  it('renders the row fields read-only', () => {
    renderRepeater(true)

    expect((screen.getByDisplayValue('First') as HTMLInputElement).readOnly).toBe(true)
    expect((screen.getByDisplayValue('Second') as HTMLInputElement).readOnly).toBe(true)
  })

  it('ignores a drop that would reorder the rows', () => {
    const { container, onChange } = renderRepeater(true)

    dragRow(container, 0, 1)

    expect(onChange).not.toHaveBeenCalled()
  })

  it('emits no row update, even from a row input that ignores readonly', () => {
    componentRegistry.registerFieldInput('normalising', NormalisingInput)
    const extra = {
      repeatables: [
        {
          shortName: 'line', uniqueKey: 'line', label: 'Line',
          fields: [{ attribute: 'name', label: 'Name', type: 'normalising', rules: [] }],
        },
      ],
    }

    const { onChange } = renderRepeater(true, extra, [{ id: 'a', type: 'line', fields: { name: 'raw' } }])

    expect(screen.getByText('raw')).toBeTruthy()
    expect(onChange).not.toHaveBeenCalled()
  })
})

describe('RepeaterFieldInput editable', () => {
  it('adds a row', () => {
    const { onChange } = renderRepeater(false)

    fireEvent.click(screen.getByRole('button', { name: 'Add Line' }))

    expect(lastRows(onChange)).toEqual(['First', 'Second', null])
  })

  it('adds a row from a template', () => {
    const { onChange } = renderRepeater(false, templates)

    fireEvent.click(screen.getByRole('button', { name: 'Add row' }))
    fireEvent.click(screen.getByText('Preset'))

    expect(lastRows(onChange)).toEqual(['First', 'Second', 'Preset'])
  })

  it('duplicates a row', () => {
    const { container, onChange } = renderRepeater(false)

    fireEvent.click(withTooltip(container, 'Duplicate row')[0])

    expect(lastRows(onChange)).toEqual(['First', 'First', 'Second'])
  })

  it('removes a row', () => {
    const { container, onChange } = renderRepeater(false)

    fireEvent.click(withTooltip(container, 'Remove')[0])

    expect(lastRows(onChange)).toEqual(['Second'])
  })

  it('reorders the rows by drag and drop', () => {
    const { container, onChange } = renderRepeater(false)

    dragRow(container, 0, 1)

    expect(lastRows(onChange)).toEqual(['Second', 'First'])
  })

  it('pastes rows', () => {
    const { onChange } = renderRepeater(false)

    fireEvent.click(screen.getByRole('button', { name: 'Paste rows' }))
    fireEvent.change(document.querySelector('textarea') as HTMLTextAreaElement, { target: { value: 'name\nThird' } })
    fireEvent.click(screen.getByRole('button', { name: 'Import' }))

    expect(lastRows(onChange)).toEqual(['First', 'Second', 'Third'])
  })

  it('edits a row field', () => {
    const { onChange } = renderRepeater(false)

    expect((screen.getByDisplayValue('First') as HTMLInputElement).readOnly).toBe(false)
    fireEvent.change(screen.getByDisplayValue('First'), { target: { value: 'First line' } })

    expect(lastRows(onChange)).toEqual(['First line', 'Second'])
  })
})
