import { beforeEach, describe, expect, it, vi } from 'vitest'
import { render, waitFor } from '@testing-library/react'

/*
 * F084: when the session boots with a user, the saved index views another user
 * (or the format before entries named their user) left in the browser's
 * storage are dropped, so an account switch, an expired session or a closed
 * tab never hands one person's search terms and filter values to the next.
 */

const apiGet = vi.fn()

vi.mock('@/lib/api', () => ({ api: { get: (...args: unknown[]) => apiGet(...args), post: vi.fn() } }))

import { AuthProvider } from '@/contexts/AuthContext'

function keys(storage: Storage): string[] {
  return Array.from({ length: storage.length }, (_, i) => storage.key(i)!).sort()
}

function seed(): void {
  for (const storage of [sessionStorage, localStorage]) {
    storage.setItem('martis:view:3:customers', JSON.stringify({ search: 'previous user' }))
    storage.setItem('martis:view:9:customers', JSON.stringify({ search: 'this user' }))
    storage.setItem('martis:view:customers', JSON.stringify({ search: 'unscoped' }))
    storage.setItem('unrelated', 'kept')
  }
}

beforeEach(() => {
  apiGet.mockReset()
  sessionStorage.clear()
  localStorage.clear()
})

describe('AuthProvider and the saved index views', () => {
  it("drops another user's views once /api/auth/user names the user", async () => {
    apiGet.mockResolvedValue({ id: 9, name: 'Ada', email: 'ada@example.com' })
    seed()

    render(<AuthProvider><span /></AuthProvider>)

    await waitFor(() => expect(keys(sessionStorage)).toEqual(['martis:view:9:customers', 'unrelated']))
    expect(keys(localStorage)).toEqual(['martis:view:9:customers', 'unrelated'])
  })

  it('does the same for a user the shell was handed', async () => {
    seed()

    render(<AuthProvider initialUser={{ id: 9, name: 'Ada', email: 'ada@example.com' }}><span /></AuthProvider>)

    await waitFor(() => expect(keys(sessionStorage)).toEqual(['martis:view:9:customers', 'unrelated']))
    expect(apiGet).not.toHaveBeenCalled()
  })

  it('drops them all when the user is a different one than before', async () => {
    seed()

    render(<AuthProvider initialUser={{ id: 12, name: 'Bo', email: 'bo@example.com' }}><span /></AuthProvider>)

    await waitFor(() => expect(keys(sessionStorage)).toEqual(['unrelated']))
    expect(keys(localStorage)).toEqual(['unrelated'])
  })

  it('leaves the storage alone while nobody is signed in', async () => {
    apiGet.mockResolvedValue(null)
    seed()

    render(<AuthProvider><span /></AuthProvider>)
    await waitFor(() => expect(apiGet).toHaveBeenCalled())
    await new Promise((resolve) => setTimeout(resolve, 20))

    expect(keys(sessionStorage)).toHaveLength(4)
  })
})
