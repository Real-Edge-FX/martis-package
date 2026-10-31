import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, waitFor } from '@testing-library/react'

/*
 * The peek card is pointer-events-none, so a value cut off with an ellipsis
 * could never be read in full. Long titles and values must wrap inside the
 * card instead of being truncated to one line.
 */

const apiGetMock = vi.fn()

vi.mock('@/lib/api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/api')>()
  return {
    ...actual,
    api: { ...actual.api, get: (...args: unknown[]) => apiGetMock(...args) },
  }
})

import { PeekCard } from './BelongsToField'

const longValue = 'Cato Copy Futures, Cato Guard Futures, Cato Copy CFDs, Cato Guard CFDs'

beforeEach(() => {
  apiGetMock.mockReset()
  apiGetMock.mockResolvedValue({
    data: { title: 'Luiz Fernando de Melo Moura', attributes: [{ label: 'Products', value: longValue }] },
  })
})

describe('PeekCard long values', () => {
  it('wraps a long value and title instead of truncating them', async () => {
    render(<PeekCard resourceKey="customers" recordId={1} triggerRect={{ top: 0, bottom: 20, left: 0 }} />)

    const cell = await waitFor(() => screen.getByText(longValue))
    expect(cell.style.whiteSpace).not.toBe('nowrap')
    expect(cell.style.textOverflow).not.toBe('ellipsis')
    expect(cell.style.overflowWrap).toBe('anywhere')

    const title = screen.getByText('Luiz Fernando de Melo Moura')
    expect(title.className).not.toContain('truncate')
  })
})
