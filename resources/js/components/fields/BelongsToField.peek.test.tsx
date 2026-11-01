import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, waitFor } from '@testing-library/react'

/*
 * The BelongsTo peek card asks `/api/resources/{resource}/{id}/peek`. Only the
 * Tag preview test pinned that URL, so swapping the segments here (a request
 * for another record) was caught by nothing on the BelongsTo side.
 */

const apiGetMock = vi.fn()

vi.mock('@/lib/api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/api')>()
  return { ...actual, api: { ...actual.api, get: (...args: unknown[]) => apiGetMock(...args) } }
})

import { PeekCard } from './BelongsToField'

beforeEach(() => {
  apiGetMock.mockReset()
  apiGetMock.mockResolvedValue({ data: { title: 'Ann', attributes: [{ label: 'Posts', value: 12 }] } })
})

describe('BelongsTo PeekCard', () => {
  it('requests the related resource and record in that order', async () => {
    render(<PeekCard resourceKey="users" recordId={7} triggerRect={{ top: 0, bottom: 10, left: 0 }} onFlipChange={() => {}} />)

    await waitFor(() => expect(screen.getByTestId('peek-card').textContent).toContain('Posts'))
    expect(apiGetMock).toHaveBeenCalledTimes(1)
    expect(apiGetMock).toHaveBeenCalledWith('/api/resources/users/7/peek')
  })
})
