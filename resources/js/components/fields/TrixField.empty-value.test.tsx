import { describe, it, expect } from 'vitest'
import { fireEvent, render, screen } from '@testing-library/react'
import type { FieldDefinition } from '@/types'
import { TrixFieldDisplay } from './TrixField'

/*
 * `TrixFieldDisplay` returned the dash for an empty value after its first
 * hooks but before its expand state and its click-interception effect, so
 * the same element rendered more hooks once its value went from empty to set
 * (a detail page refetch, polling, an inline edit) and fewer once it was
 * cleared: React threw ("Rendered more hooks than during the previous
 * render") and the page crashed. The hooks now run on every render and the
 * output is the same.
 */

/** The placeholder a display renders for an empty value (an em dash). */
const DASH = '\u2014'

function makeField(alwaysShow: boolean): FieldDefinition {
  return {
    attribute: 'body', label: 'Body', type: 'trix', alwaysShow,
    nullable: true, readonly: false, required: false, sortable: false,
    searchable: false, showOnIndex: false, showOnDetail: true, showOnForms: true,
    rules: [],
  } as unknown as FieldDefinition
}

describe('TrixFieldDisplay value changes', () => {
  it('renders the content once an empty value is set, and the dash once it is cleared', () => {
    const field = makeField(true)
    const { container, rerender } = render(<TrixFieldDisplay field={field} value="" />)
    expect(container.textContent).toBe(DASH)

    rerender(<TrixFieldDisplay field={field} value="<div>Hello <strong>world</strong></div>" />)
    expect(container.querySelector('.trix-content strong')?.textContent).toBe('world')

    rerender(<TrixFieldDisplay field={field} value={null} />)
    expect(container.textContent).toBe(DASH)

    rerender(<TrixFieldDisplay field={field} value="<div>Again</div>" />)
    expect(container.querySelector('.trix-content')?.textContent).toBe('Again')
  })

  it('keeps the show / hide toggle of a collapsed field once its value is set', () => {
    const field = makeField(false)
    const { container, rerender } = render(<TrixFieldDisplay field={field} value={undefined} />)
    expect(container.textContent).toBe(DASH)

    rerender(<TrixFieldDisplay field={field} value="<div>Hidden until asked</div>" />)
    expect(container.querySelector('.trix-content')).toBeNull()

    fireEvent.click(screen.getByRole('button'))
    expect(container.querySelector('.trix-content')?.textContent).toBe('Hidden until asked')

    rerender(<TrixFieldDisplay field={field} value="" />)
    expect(container.textContent).toBe(DASH)
  })
})
