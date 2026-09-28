import { describe, it, expect, vi, beforeEach } from 'vitest'

const post = vi.fn()
vi.mock('@/lib/api', () => ({ api: { post: (...args: unknown[]) => post(...args) } }))

import { signOut } from './signOut'
import { BASE_PATH } from '@/lib/config'

describe('signOut', () => {
  beforeEach(() => {
    post.mockReset()
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
})
