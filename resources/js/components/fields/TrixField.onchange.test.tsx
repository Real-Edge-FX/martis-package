import { describe, it, expect, vi } from 'vitest'
import { StrictMode, useState } from 'react'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import type { FieldDefinition } from '@/types'
import { TrixFieldInput } from './TrixField'
import { RepeaterFieldInput } from './RepeaterField'
import { registerDefaultFields } from './FieldRenderer'

/*
 * `TrixFieldInput` builds its editor once, in an effect, and subscribed the
 * editor's `trix-change` event to the `onChange` of the render that built
 * it. Every later edit went to that first callback. A Repeater row hands its
 * fields a callback that builds on the rows of that render, so an edit to a
 * Trix field put back the row fields as they were when the editor mounted:
 * a title typed since then was lost. The editor now reports through the
 * `onChange` of the latest render.
 *
 * The effect's cleanup also dropped that subscription without rebuilding the
 * editor, so under StrictMode (the dev server) the editor React set up again
 * never reported a change at all. The cleanup now tears the editor down and
 * the effect builds it again.
 *
 * Trix itself stays out of jsdom: the tests set the editor's hidden input
 * and dispatch the `trix-change` event the editor fires on an edit.
 */

vi.mock('trix', () => ({}))

registerDefaultFields()

const field = {
  attribute: 'body', label: 'Body', type: 'trix',
  nullable: true, readonly: false, required: false, sortable: false,
  searchable: false, showOnIndex: false, showOnDetail: true, showOnForms: true,
  rules: [],
} as unknown as FieldDefinition

/** Edit the editor inside `root` the way Trix reports an edit. */
function edit(root: ParentNode, html: string) {
  const input = root.querySelector<HTMLInputElement>('input[id^="trix-input-"]')
  const editor = root.querySelector('trix-editor')
  if (!input || !editor) throw new Error('no Trix editor rendered')
  input.value = html
  fireEvent(editor, new Event('trix-change'))
}

describe('TrixFieldInput change reporting', () => {
  it('reports an edit through the onChange of the latest render', () => {
    const first = vi.fn()
    const latest = vi.fn()
    const { container, rerender } = render(<TrixFieldInput field={field} value="" onChange={first} />)
    rerender(<TrixFieldInput field={field} value="" onChange={latest} />)

    edit(container, '<div>Hello</div>')

    expect(latest).toHaveBeenCalledWith('<div>Hello</div>')
    expect(first).not.toHaveBeenCalled()
  })

  it('reports an edit under StrictMode, with a single editor', () => {
    const onChange = vi.fn()
    const { container } = render(
      <StrictMode>
        <TrixFieldInput field={field} value="<div>Stored</div>" onChange={onChange} />
      </StrictMode>,
    )

    expect(container.querySelectorAll('trix-editor')).toHaveLength(1)
    expect(container.querySelector<HTMLInputElement>('input[id^="trix-input-"]')?.value).toBe('<div>Stored</div>')

    edit(container, '<div>Edited</div>')

    expect(onChange).toHaveBeenCalledWith('<div>Edited</div>')
  })
})

describe('TrixFieldInput in a Repeater row', () => {
  const repeater = {
    attribute: 'blocks', label: 'Blocks', type: 'repeater',
    nullable: true, readonly: false, required: false, sortable: false,
    searchable: false, showOnIndex: false, showOnDetail: true, showOnForms: true,
    rules: [], storage: 'json',
    repeatables: [
      {
        shortName: 'text_block', uniqueKey: 'text_block', label: 'Text block',
        fields: [
          { attribute: 'title', label: 'Title', type: 'text', rules: [] },
          { attribute: 'body', label: 'Body', type: 'trix', rules: [] },
        ],
      },
    ],
  } as unknown as FieldDefinition

  const stored = [{ id: 'a', type: 'text_block', fields: { title: 'Intro', body: '<div>Old</div>' } }]

  // A form that stores what the Repeater emits, as the edit and create forms do.
  function Form() {
    const [value, setValue] = useState<unknown>(stored)
    return (
      <>
        <RepeaterFieldInput field={repeater} value={value} onChange={setValue} />
        <output data-testid="stored">{JSON.stringify(value)}</output>
      </>
    )
  }

  it('keeps the edits to the other fields of the row when the Trix field changes', async () => {
    const { container } = render(<Form />)
    await waitFor(() => expect(container.querySelector('trix-editor')).not.toBeNull())

    fireEvent.change(screen.getByDisplayValue('Intro'), { target: { value: 'Intro,' } })
    fireEvent.change(screen.getByDisplayValue('Intro,'), { target: { value: 'Intro, revised' } })
    edit(container, '<div>New</div>')

    const rows = JSON.parse(screen.getByTestId('stored').textContent ?? 'null') as Array<{ fields: Record<string, unknown> }>
    expect(rows[0].fields).toEqual({ title: 'Intro, revised', body: '<div>New</div>' })
  })
})
