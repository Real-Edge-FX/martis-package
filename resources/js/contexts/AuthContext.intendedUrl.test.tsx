import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { renderHook, waitFor } from '@testing-library/react'

/*
 * v2.21.0: the response that completes a sign-in carries `intended_url`, the
 * page asked for before the sign-in. login() opens it with a full page load
 * when it is a path under the base path on this origin; anything else is
 * dropped and the SPA behaves as before. The user state never carries the key.
 */

const { get, post } = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }))

vi.mock('@/lib/api', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/lib/api')>()),
  api: { get: (...args: unknown[]) => get(...args), post: (...args: unknown[]) => post(...args) },
}))
vi.mock('@/lib/config', () => ({ config: { auth: {} }, BASE_PATH: '/martis', API_BASE_URL: 'http://localhost/martis' }))

import { AuthProvider, useAuth } from './AuthContext'

const original = window.location
let location: { href: string; pathname: string; origin: string }

const user = { id: 1, name: 'Ada', email: 'ada@example.com' }

async function signIn(response: unknown) {
  get.mockResolvedValue(null)
  post.mockResolvedValue(response)
  const hook = renderHook(() => useAuth(), { wrapper: AuthProvider })
  await waitFor(() => expect(hook.result.current.isLoading).toBe(false))
  await hook.result.current.login('ada@example.com', 'secret')
  return hook
}

beforeEach(() => {
  get.mockReset()
  post.mockReset()
  location = { href: 'http://localhost/martis/login', pathname: '/martis/login', origin: 'http://localhost' }
  Object.defineProperty(window, 'location', { configurable: true, value: location })
})

afterEach(() => {
  Object.defineProperty(window, 'location', { configurable: true, value: original })
})

describe('AuthContext.login and the intended URL', () => {
  it('opens a path under the base path with a full load', async () => {
    await signIn({ ...user, intended_url: '/martis/sso/issue/partner?state=abc' })

    expect(location.href).toBe('/martis/sso/issue/partner?state=abc')
  })

  it.each([
    ['an absolute foreign URL', 'https://evil.example/martis/x'],
    ['a protocol-relative URL', '//evil.example/martis/x'],
    ['a path outside the base path', '/other/page'],
    ['a dot segment that leaves the base path', '/martis/../other'],
    ['a value that is not a string', 42],
  ])('ignores %s: no navigation, the user is signed in without the key', async (_label, intended) => {
    const { result } = await signIn({ ...user, intended_url: intended })

    expect(location.href).toBe('http://localhost/martis/login')
    await waitFor(() => expect(result.current.user).toEqual(user))
    expect(result.current.user).not.toHaveProperty('intended_url')
  })

  it('behaves as before when the response carries none', async () => {
    const { result } = await signIn(user)

    expect(location.href).toBe('http://localhost/martis/login')
    await waitFor(() => expect(result.current.user).toEqual(user))
  })
})
