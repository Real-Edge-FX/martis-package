import { describe, it, expect, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import type { FieldDefinition } from '@/types'
import { CodeFieldDisplay } from './CodeField'

/*
 * `CodeFieldDisplay` returned the dash for an empty value before it called
 * its memo hooks, so the same element called a different number of hooks
 * once its value went from empty to set (a detail page refetch, polling, an
 * inline edit) or back. It got away with it only because no hook ran before
 * that return: React mounts the hooks afresh when the previous render called
 * none. Any hook added above the return (a translation, like the Markdown and
 * Trix displays have) would have made the element throw on such a change.
 * The hooks now run on every render; these tests pin the output across the
 * changes: the dash for an empty value, the read-only editor for a set one.
 *
 * CodeMirror itself measures the layout, which jsdom does not implement: a
 * stub stands in for it and shows the value it is handed.
 */

/** The placeholder a display renders for an empty value (an em dash). */
const DASH = '\u2014'

vi.mock('@uiw/react-codemirror', () => ({
  default: ({ value }: { value: string }) => <pre data-testid="code-viewer">{value}</pre>,
}))

const field = {
  attribute: 'snippet', label: 'Snippet', type: 'code', language: 'javascript',
  nullable: true, readonly: false, required: false, sortable: false,
  searchable: false, showOnIndex: false, showOnDetail: true, showOnForms: true,
  rules: [],
} as unknown as FieldDefinition

describe('CodeFieldDisplay value changes', () => {
  it('shows the code once an empty value is set, and the dash once it is cleared', () => {
    const { container, rerender } = render(<CodeFieldDisplay field={field} value="" />)
    expect(container.textContent).toBe(DASH)

    rerender(<CodeFieldDisplay field={field} value="const answer = 42" />)
    expect(screen.getByTestId('code-viewer').textContent).toBe('const answer = 42')

    rerender(<CodeFieldDisplay field={field} value={null} />)
    expect(container.textContent).toBe(DASH)

    rerender(<CodeFieldDisplay field={field} value="const answer = 43" />)
    expect(screen.getByTestId('code-viewer').textContent).toBe('const answer = 43')
  })

  it('shows the dash once a set value is cleared', () => {
    const { container, rerender } = render(<CodeFieldDisplay field={field} value="const answer = 42" />)
    expect(screen.getByTestId('code-viewer').textContent).toBe('const answer = 42')

    rerender(<CodeFieldDisplay field={field} value="" />)
    expect(container.textContent).toBe(DASH)
  })
})
