import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

// A session that SSO opened and the server ended (too old, revoked, or the
// provider says the person is gone, v2.21.0) answers 401 with
// `{ message, sso_reauth_url }`. The SPA leaves the page for that URL, from
// request() and uploadRequest() alike, instead of the session-expired login
// page, and only for a path under the Martis base path on its own origin.

vi.mock('@/lib/config', () => ({ config: { auth: {} }, BASE_PATH: '/martis', API_BASE_URL: 'http://localhost/martis' }))

import { api, ApiError } from '@/lib/api'

const original = window.location
let location: { href: string; pathname: string; origin: string }

function answer(status: number, body: unknown): void {
  vi.stubGlobal('fetch', vi.fn(async () => new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })))
}

beforeEach(() => {
  location = { href: 'http://localhost/martis/resources/users', pathname: '/martis/resources/users', origin: 'http://localhost' }
  Object.defineProperty(window, 'location', { configurable: true, value: location })
})

afterEach(() => {
  Object.defineProperty(window, 'location', { configurable: true, value: original })
  vi.unstubAllGlobals()
})

describe('the SSO re-authentication signal', () => {
  it('follows a same-origin path under the Martis base path', async () => {
    answer(401, { message: 'Your session has ended.', sso_reauth_url: '/martis/sso/azure/redirect' })

    await expect(api.get('/api/tools')).rejects.toBeInstanceOf(ApiError)
    expect(location.href).toBe('/martis/sso/azure/redirect')
  })

  it('does the same from uploadRequest()', async () => {
    answer(401, { message: 'Your session has ended.', sso_reauth_url: '/martis/sso/azure/redirect' })

    await expect(api.upload('POST', '/api/resources/users', { name: 'Ada' })).rejects.toBeInstanceOf(ApiError)
    expect(location.href).toBe('/martis/sso/azure/redirect')
  })

  it('follows it from a public probe too: only a session the server just ended carries it', async () => {
    answer(401, { sso_reauth_url: '/martis/sso/azure/redirect' })

    await expect(api.get('/api/auth/user')).rejects.toBeInstanceOf(ApiError)
    expect(location.href).toBe('/martis/sso/azure/redirect')
  })

  it.each([
    ['an absolute URL', 'https://evil.example/martis/sso/azure/redirect'],
    ['an absolute URL on this origin', 'http://localhost/martis/sso/azure/redirect'],
    ['a protocol-relative URL', '//evil.example/martis/sso/azure/redirect'],
    ['a path outside the base path', '/other/sso/azure/redirect'],
    ['a path that only starts like the base path', '/martis-evil/sso/azure/redirect'],
    ['a dot segment that leaves the base path', '/martis/../other'],
    ['an encoded dot segment that leaves the base path', '/martis/%2e%2e/other'],
    ['a backslash that the browser reads as a slash', '/martis/..\\other'],
    ['a javascript URL', 'javascript:alert(1)'],
    ['a relative path', 'sso/azure/redirect'],
    ['an empty string', ''],
    ['a number', 7],
    ['an object', { path: '/martis/sso/azure/redirect' }],
  ])('ignores %s and takes the normal session-expired redirect', async (_label, url) => {
    answer(401, { message: 'Your session has ended.', sso_reauth_url: url })

    await expect(api.get('/api/tools')).rejects.toBeInstanceOf(ApiError)
    expect(location.href).toBe('/martis/login?expired=1')
  })

  it('keeps the session-expired redirect for a 401 without the key', async () => {
    answer(401, { message: 'Unauthenticated.' })

    await expect(api.get('/api/tools')).rejects.toBeInstanceOf(ApiError)
    expect(location.href).toBe('/martis/login?expired=1')
  })

  it('ignores the key on a response that is not a 401', async () => {
    answer(403, { sso_reauth_url: '/martis/sso/azure/redirect' })

    await expect(api.get('/api/tools')).rejects.toBeInstanceOf(ApiError)
    expect(location.href).toBe('http://localhost/martis/resources/users')
  })
})
