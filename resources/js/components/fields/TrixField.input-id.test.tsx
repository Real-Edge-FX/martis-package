import { describe, it, expect, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import type { FieldDefinition } from '@/types'
import { TrixFieldInput } from './TrixField'

/*
 * A Trix editor finds the hidden input it loads from and writes to by id,
 * in the whole document (`getElementById(editor.getAttribute('input'))`).
 * `TrixFieldInput` named that input after the field's attribute alone, so a
 * second editor for the same attribute on the page (the next row of a
 * Repeater, an inline-create modal over a form with the same field) found
 * the first one's input: it showed the first editor's content and wrote its
 * edits there, while it reported its own untouched input to the form. Every
 * editor now gets an input id of its own.
 */

vi.mock('trix', () => ({}))

const field = {
  attribute: 'body', label: 'Body', type: 'trix',
  nullable: true, readonly: false, required: false, sortable: false,
  searchable: false, showOnIndex: false, showOnDetail: true, showOnForms: true,
  rules: [],
} as unknown as FieldDefinition

describe('TrixFieldInput hidden input', () => {
  it('binds each editor to its own input when two editors edit the same attribute', () => {
    render(
      <>
        <div data-testid="first">
          <TrixFieldInput field={field} value="<div>First</div>" onChange={() => {}} />
        </div>
        <div data-testid="second">
          <TrixFieldInput field={field} value="<div>Second</div>" onChange={() => {}} />
        </div>
      </>,
    )

    for (const [testId, html] of [['first', '<div>First</div>'], ['second', '<div>Second</div>']]) {
      const root = screen.getByTestId(testId)
      const editor = root.querySelector('trix-editor')
      const input = document.getElementById(editor?.getAttribute('input') ?? '') as HTMLInputElement | null

      expect(input && root.contains(input)).toBe(true)
      expect(input?.value).toBe(html)
    }
  })
})
