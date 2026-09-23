import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, fireEvent, screen, waitFor, act } from '@testing-library/react'
import type { FieldDefinition } from '@/types'

/*
 * `Tag::withPreview()` is documented to open a preview of a tag's record on
 * hover, and the field serialised the flag, but the display never read it.
 * A tag now shows the related resource's peek card (its `fieldsForPreview()`,
 * the card `BelongsTo` shows) after a short hover, and hides it when the
 * pointer leaves; without the flag a tag stays a plain chip.
 */

const apiGetMock = vi.fn()

vi.mock('@/lib/api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/api')>()
  return {
    ...actual,
    api: { ...actual.api, get: (...args: unknown[]) => apiGetMock(...args) },
  }
})

import { TagFieldDisplay } from './TagField'

function makeField(withPreview: boolean): FieldDefinition {
  return {
    attribute: 'tags', label: 'Tags', type: 'tag', relatedResource: 'tags', withPreview,
    nullable: true, readonly: false, required: false, sortable: false,
    searchable: false, showOnIndex: true, showOnDetail: true, showOnForms: true,
    rules: [],
  } as unknown as FieldDefinition
}

const value = [{ id: 1, title: 'php' }, { id: 2, title: 'laravel' }]

const peekCard = () => screen.queryByTestId('peek-card')

beforeEach(() => {
  apiGetMock.mockReset()
  apiGetMock.mockResolvedValue({ data: { title: 'php', attributes: [{ label: 'Posts', value: 12 }] } })
})

describe('TagFieldDisplay withPreview', () => {
  it("shows the tag's peek card on hover and hides it when the pointer leaves", async () => {
    render(<TagFieldDisplay field={makeField(true)} value={value} />)
    const tag = screen.getByText('php')

    fireEvent.mouseEnter(tag)

    await waitFor(() => expect(peekCard()?.textContent).toContain('Posts'))
    expect(apiGetMock).toHaveBeenCalledWith('/api/resources/tags/1/peek')
    fireEvent.mouseLeave(tag)
    expect(peekCard()).toBeNull()
  })

  it('shows no preview without withPreview()', async () => {
    render(<TagFieldDisplay field={makeField(false)} value={value} />)

    fireEvent.mouseEnter(screen.getByText('php'))
    await act(async () => { await new Promise((resolve) => setTimeout(resolve, 400)) })

    expect(peekCard()).toBeNull()
    expect(apiGetMock).not.toHaveBeenCalled()
  })
})
