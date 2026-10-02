import { describe, it, expect, vi, beforeEach } from 'vitest'

const post = vi.fn()
vi.mock('@/lib/api', () => ({ api: { post: (...args: unknown[]) => post(...args) } }))

import { signOut } from './signOut'
import { BASE_PATH } from '@/lib/config'

function keys(storage: Storage): string[] {
  return Array.from({ length: storage.length }, (_, i) => storage.key(i)!).sort()
}

describe('signOut', () => {
  beforeEach(() => {
    post.mockReset()
    sessionStorage.clear()
    localStorage.clear()
  })

  it('ends the session, then reloads on the login page', async () => {
    post.mockResolvedValue({})
    const redirect = vi.fn()

    await signOut(redirect)

    expect(post).toHaveBeenCalledWith('/api/auth/logout')
    expect(redirect).toHaveBeenCalledWith(BASE_PATH + '/login')
  })

  it('still goes to the login page when the session is already gone', async () => {
    post.mockRejectedValue(new Error('419'))
    const redirect = vi.fn()

    await signOut(redirect)

    expect(redirect).toHaveBeenCalledWith(BASE_PATH + '/login')
  })

  // F084: the saved index views (search terms, filter values) outlive a
  // sign-out in the tab's sessionStorage (the login page loads in the same
  // tab) and in localStorage (the whole browser profile).
  it('drops every saved index view of every user, from both storages, before leaving the page', async () => {
    post.mockResolvedValue({})
    for (const storage of [sessionStorage, localStorage]) {
      storage.setItem('martis:view:7:customers', JSON.stringify({ search: 'alice@example.com' }))
      storage.setItem('martis:view:8:orders', '{}')
      storage.setItem('martis:view:customers', '{}')
      storage.setItem('unrelated', 'kept')
    }
    let seenAtRedirect: string[] = []
    const redirect = vi.fn(() => {
      seenAtRedirect = [...keys(sessionStorage), ...keys(localStorage)]
    })

    await signOut(redirect)

    expect(seenAtRedirect).toEqual(['unrelated', 'unrelated'])
  })

  it('drops the saved index views even when the logout request fails', async () => {
    post.mockRejectedValue(new Error('500'))
    sessionStorage.setItem('martis:view:7:customers', '{}')

    await signOut(vi.fn())

    expect(keys(sessionStorage)).toEqual([])
  })

  // F095: the preferences cache and the guest-pick marker go with the session.
  it('drops the preferences cache and the guest-pick marker', async () => {
    post.mockResolvedValue({})
    localStorage.setItem('martis-preferences', JSON.stringify({ theme: 'light' }))
    localStorage.setItem('martis-preferences-guest-modified', '1')
    sessionStorage.setItem('martis-preferences-guest-modified', '1')

    await signOut(vi.fn())

    expect(keys(localStorage)).toEqual([])
    expect(keys(sessionStorage)).toEqual([])
  })
})
