import { describe, it, expect } from 'vitest'
import { fireEvent, render, screen } from '@testing-library/react'
import type { FieldDefinition } from '@/types'
import { MarkdownFieldDisplay } from './MarkdownField'

/*
 * `MarkdownFieldDisplay` returned the dash for an empty value after its
 * translation hook but before its own state and memo, so the same element
 * rendered more hooks once its value went from empty to set (a detail page
 * refetch, polling, an inline edit) and fewer once it was cleared: React
 * threw ("Rendered more hooks than during the previous render") and the page
 * crashed. The hooks now run on every render and the output is the same.
 */

/** The placeholder a display renders for an empty value (an em dash). */
const DASH = '\u2014'

function makeField(alwaysShow: boolean): FieldDefinition {
  return {
    attribute: 'notes', label: 'Notes', type: 'markdown', alwaysShow, preset: 'default',
    nullable: true, readonly: false, required: false, sortable: false,
    searchable: false, showOnIndex: false, showOnDetail: true, showOnForms: true,
    rules: [],
  } as unknown as FieldDefinition
}

describe('MarkdownFieldDisplay value changes', () => {
  it('renders the markdown once an empty value is set, and the dash once it is cleared', () => {
    const field = makeField(true)
    const { container, rerender } = render(<MarkdownFieldDisplay field={field} value="" />)
    expect(container.textContent).toBe(DASH)

    rerender(<MarkdownFieldDisplay field={field} value="# Release notes" />)
    expect(screen.getByRole('heading', { name: 'Release notes' })).toBeTruthy()

    rerender(<MarkdownFieldDisplay field={field} value={null} />)
    expect(container.textContent).toBe(DASH)

    rerender(<MarkdownFieldDisplay field={field} value="# Next release" />)
    expect(screen.getByRole('heading', { name: 'Next release' })).toBeTruthy()
  })

  it('keeps the show / hide toggle of a collapsed field once its value is set', () => {
    const field = makeField(false)
    const { container, rerender } = render(<MarkdownFieldDisplay field={field} value={undefined} />)
    expect(container.textContent).toBe(DASH)

    rerender(<MarkdownFieldDisplay field={field} value="**Bold** move" />)
    expect(container.querySelector('strong')).toBeNull()

    fireEvent.click(screen.getByRole('button'))
    expect(container.querySelector('strong')?.textContent).toBe('Bold')

    rerender(<MarkdownFieldDisplay field={field} value="" />)
    expect(container.textContent).toBe(DASH)
  })
})
