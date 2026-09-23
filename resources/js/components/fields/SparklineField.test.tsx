import { describe, it, expect } from 'vitest'
import { useState } from 'react'
import { fireEvent, render, screen } from '@testing-library/react'
import type { FieldDefinition } from '@/types'
import { SparklineFieldInput } from './SparklineField'

/*
 * The Sparkline input edits its numbers as JSON text and hands the parsed
 * array to the form. It rewrote the text from every value the form handed
 * back, its own included, so the text the user typed was reformatted under
 * them ("[1, 2, 3]" became "[1,2,3]", an emptied box became "[]"), and a
 * value from outside (a cleared form) left the previous parse error on
 * screen. The text now follows only a value the input did not emit itself.
 */

const field = {
  attribute: 'trend', label: 'Trend', type: 'sparkline',
  nullable: true, readonly: false, required: false, sortable: false,
  searchable: false, showOnIndex: false, showOnDetail: true, showOnForms: true,
  rules: [],
} as unknown as FieldDefinition

// A form that stores what the input emits and can be cleared the way
// "Create & add another" clears it, or filled the way an edit form is.
function Form() {
  const [values, setValues] = useState<Record<string, unknown>>({})
  return (
    <>
      <SparklineFieldInput
        field={field}
        value={values.trend ?? null}
        onChange={(v) => setValues((prev) => ({ ...prev, trend: v }))}
      />
      <button type="button" onClick={() => setValues({})}>Clear form</button>
      <button type="button" onClick={() => setValues({ trend: [4, 5] })}>Load record</button>
      <output data-testid="stored">{JSON.stringify(values.trend ?? null)}</output>
    </>
  )
}

const text = () => screen.getByRole('textbox') as HTMLTextAreaElement
const stored = () => JSON.parse(screen.getByTestId('stored').textContent ?? 'null') as unknown

describe('SparklineFieldInput text', () => {
  it('keeps the text as the user typed it when the form hands back the parsed numbers', () => {
    render(<Form />)

    fireEvent.change(text(), { target: { value: '[1, 2, 3]' } })

    expect(stored()).toEqual([1, 2, 3])
    expect(text().value).toBe('[1, 2, 3]')
  })

  it('keeps an emptied box empty', () => {
    render(<Form />)
    fireEvent.change(text(), { target: { value: '[1, 2]' } })

    fireEvent.change(text(), { target: { value: '' } })

    expect(stored()).toEqual([])
    expect(text().value).toBe('')
  })

  it('starts over, parse error included, when the form is cleared', () => {
    render(<Form />)
    fireEvent.change(text(), { target: { value: '[1, 2]' } })
    fireEvent.change(text(), { target: { value: '[1, 2' } })
    expect(screen.getByText('Invalid JSON')).toBeTruthy()

    fireEvent.click(screen.getByRole('button', { name: 'Clear form' }))

    expect(text().value).toBe('[]')
    expect(screen.queryByText('Invalid JSON')).toBeNull()
  })

  it('shows the numbers the form hands in after mount', () => {
    render(<Form />)

    fireEvent.click(screen.getByRole('button', { name: 'Load record' }))

    expect(text().value).toBe('[4,5]')
    expect(screen.getByText('2 values')).toBeTruthy()
  })
})
