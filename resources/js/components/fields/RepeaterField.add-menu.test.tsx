import { describe, expect, it, vi } from 'vitest'
import { fireEvent, render, screen } from '@testing-library/react'
import type { FieldDefinition } from '@/types'
import { RepeaterFieldInput } from './RepeaterField'
import { registerDefaultFields } from './FieldRenderer'

/*
 * The add-row menu of a Repeater with several row types renders in
 * document.body, so a modal body or drawer that scrolls does not cut it. A
 * press inside it is not an outside click: the row type picked is added.
 */

registerDefaultFields()

const field = {
  attribute: 'blocks', label: 'Blocks', type: 'repeater',
  nullable: true, readonly: false, required: false, sortable: false,
  searchable: false, showOnIndex: false, showOnDetail: true, showOnForms: true,
  rules: [], storage: 'json',
  repeatables: [
    { shortName: 'text', uniqueKey: 'text', label: 'Text block', fields: [{ attribute: 'body', label: 'Body', type: 'text', rules: [] }] },
    { shortName: 'quote', uniqueKey: 'quote', label: 'Quote block', fields: [{ attribute: 'body', label: 'Body', type: 'text', rules: [] }] },
  ],
} as unknown as FieldDefinition

describe('Repeater add-row menu', () => {
  it('renders in document.body and adds the row type pressed in it', () => {
    const onChange = vi.fn()
    const { container } = render(<RepeaterFieldInput field={field} value={[]} onChange={onChange} />)

    fireEvent.click(screen.getByRole('button', { name: /Add row/ }))
    const item = screen.getByRole('button', { name: 'Quote block' })
    const menu = item.closest('[data-martis-floating-panel]') as HTMLElement
    expect(menu).not.toBeNull()
    expect(container.contains(menu)).toBe(false)

    fireEvent.mouseDown(item)
    fireEvent.click(item)
    expect(onChange).toHaveBeenCalledTimes(1)
    expect((onChange.mock.calls[0][0] as Array<{ type: string }>)[0].type).toBe('quote')
  })
})
