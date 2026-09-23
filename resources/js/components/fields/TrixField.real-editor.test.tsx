import { describe, it, expect, vi } from 'vitest'
import { StrictMode } from 'react'
import { render, screen, waitFor } from '@testing-library/react'
import type { FieldDefinition } from '@/types'
import { TrixFieldInput } from './TrixField'

/*
 * The Trix input contracts, with the real Trix editor: two editors for the
 * same attribute each load their own content and report their own edits,
 * under StrictMode (the dev server), which builds every editor twice.
 * Before, the second editor loaded the first one's content (both hidden
 * inputs had the same id) and, under StrictMode, no edit reached the form.
 *
 * jsdom's ElementInternals has no form validity API, which Trix uses when
 * the browser offers ElementInternals. Without it Trix keeps its value in
 * the hidden input alone, as it does in a browser without ElementInternals.
 */

vi.hoisted(() => {
  delete (window as unknown as { ElementInternals?: unknown }).ElementInternals
})

type TrixElement = HTMLElement & {
  editor?: { insertString(text: string): void; getDocument(): { toString(): string } }
}

const field = {
  attribute: 'body', label: 'Body', type: 'trix',
  nullable: true, readonly: false, required: false, sortable: false,
  searchable: false, showOnIndex: false, showOnDetail: true, showOnForms: true,
  rules: [],
} as unknown as FieldDefinition

const editorIn = (testId: string) => screen.getByTestId(testId).querySelector('trix-editor') as TrixElement

describe('TrixFieldInput with the Trix editor', () => {
  it('loads and reports its own content next to another editor of the same attribute', async () => {
    const onFirst = vi.fn()
    const onSecond = vi.fn()
    render(
      <StrictMode>
        <div data-testid="first">
          <TrixFieldInput field={field} value="<div>First</div>" onChange={onFirst} />
        </div>
        <div data-testid="second">
          <TrixFieldInput field={field} value="<div>Second</div>" onChange={onSecond} />
        </div>
      </StrictMode>,
    )
    await waitFor(() => expect(editorIn('second').editor).toBeTruthy())

    expect(document.querySelectorAll('trix-editor')).toHaveLength(2)
    expect(document.querySelectorAll('trix-toolbar')).toHaveLength(2)
    expect(editorIn('first').editor?.getDocument().toString()).toBe('First\n')
    expect(editorIn('second').editor?.getDocument().toString()).toBe('Second\n')

    editorIn('second').focus()
    editorIn('second').editor?.insertString('More ')

    await waitFor(() => expect(onSecond).toHaveBeenCalledWith('<div>More Second</div>'))
    expect(onFirst).not.toHaveBeenCalled()
    // The real editor builds four times over (two editors, StrictMode):
    // room above the 5 s default for a loaded runner.
  }, 15_000)
})
