import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

// A session that SSO opened and the server ended (too old, revoked, or the
// provider says the person is gone, v2.21.0) answers 401 with
// `{ message, sso_reauth_url }`. The SPA leaves the page for that URL, from
// request() and uploadRequest() alike, instead of the session-expired login
// page, and only for a path under the Martis base path on its own origin.
//
// A page fires several requests at once and each of them can answer 401, with
// the URL or (a browser the server has nothing on) without it. Whatever the
// order they arrive in, the page ends in exactly one navigation: the way back
// through the provider when any of them carried it, else the login page.

vi.mock('@/lib/config', () => ({ config: { auth: {} }, BASE_PATH: '/martis', API_BASE_URL: 'http://localhost/martis' }))

import type { api as ApiClient, ApiError as ApiErrorClass } from '@/lib/api'

// The module keeps per-page state (a reauth navigation that is under way), so
// every test starts from a page that has just loaded.
let api: typeof ApiClient
let ApiError: typeof ApiErrorClass

const original = window.location
let location: { href: string; pathname: string; origin: string }

function answer(status: number, body: unknown): void {
  vi.stubGlobal('fetch', vi.fn(async () => new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })))
}

/** Every assignment to `location.href`, in order. */
let navigations: string[]

beforeEach(async () => {
  navigations = []
  let href = 'http://localhost/martis/resources/users'
  location = {
    get href() { return href },
    set href(value: string) { href = value; navigations.push(value) },
    pathname: '/martis/resources/users',
    origin: 'http://localhost',
  }
  Object.defineProperty(window, 'location', { configurable: true, value: location })
  vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] })
  vi.resetModules()
  ;({ api, ApiError } = await import('@/lib/api'))
})

afterEach(() => {
  vi.useRealTimers()
  Object.defineProperty(window, 'location', { configurable: true, value: original })
  vi.unstubAllGlobals()
})

/** Let a bare 401's grace period (the wait for a way back that may still arrive) pass. */
async function settle(): Promise<void> {
  await vi.advanceTimersByTimeAsync(5000)
}

describe('the SSO re-authentication signal', () => {
  it('follows a same-origin path under the Martis base path', async () => {
    answer(401, { message: 'Your session has ended.', sso_reauth_url: '/martis/sso/azure/redirect' })

    await expect(api.get('/api/tools')).rejects.toBeInstanceOf(ApiError)
    expect(navigations).toEqual(['/martis/sso/azure/redirect'])
  })

  it('does the same from uploadRequest()', async () => {
    answer(401, { message: 'Your session has ended.', sso_reauth_url: '/martis/sso/azure/redirect' })

    await expect(api.upload('POST', '/api/resources/users', { name: 'Ada' })).rejects.toBeInstanceOf(ApiError)
    expect(navigations).toEqual(['/martis/sso/azure/redirect'])
  })

  it('follows it from a public probe too: only a session the server just ended carries it', async () => {
    answer(401, { sso_reauth_url: '/martis/sso/azure/redirect' })

    await expect(api.get('/api/auth/user')).rejects.toBeInstanceOf(ApiError)
    expect(navigations).toEqual(['/martis/sso/azure/redirect'])
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
    await settle()
    expect(navigations).toEqual(['/martis/login?expired=1'])
  })

  it('keeps the session-expired redirect for a 401 without the key', async () => {
    answer(401, { message: 'Unauthenticated.' })

    await expect(api.get('/api/tools')).rejects.toBeInstanceOf(ApiError)
    await settle()
    expect(navigations).toEqual(['/martis/login?expired=1'])
  })

  it('ignores the key on a response that is not a 401', async () => {
    answer(403, { sso_reauth_url: '/martis/sso/azure/redirect' })

    await expect(api.get('/api/tools')).rejects.toBeInstanceOf(ApiError)
    await settle()
    expect(navigations).toEqual([])
  })
})

type Answer = 'reauth' | 'bare'

/**
 * Three requests of one page in flight; each answers 401, with the way back
 * ('reauth') or without ('bare'), and the answers arrive in `order` (indexes
 * into `answers`), one handled before the next arrives.
 */
async function concurrent(answers: Answer[], order: number[]): Promise<void> {
  const resolvers: Array<(response: Response) => void> = []
  vi.stubGlobal('fetch', vi.fn(() => new Promise<Response>((resolve) => { resolvers.push(resolve) })))

  const requests = answers.map((_, i) => api.get(`/api/request-${i}`).catch(() => undefined))

  for (const index of order) {
    const body = answers[index] === 'reauth'
      ? { message: 'Your session has ended.', sso_reauth_url: '/martis/sso/azure/redirect' }
      : {}
    resolvers[index](new Response(JSON.stringify(body), { status: 401, headers: { 'Content-Type': 'application/json' } }))
    // Handle this answer completely before the next one arrives.
    await vi.advanceTimersByTimeAsync(10)
  }

  await Promise.all(requests)
  await settle()
}

const orders = [[0, 1, 2], [0, 2, 1], [1, 0, 2], [1, 2, 0], [2, 0, 1], [2, 1, 0]]

describe('concurrent 401s of one page', () => {
  it.each(orders.flatMap((order) => [
    [['reauth', 'bare', 'bare'], order],
    [['bare', 'reauth', 'bare'], order],
    [['reauth', 'reauth', 'bare'], order],
    [['reauth', 'reauth', 'reauth'], order],
  ] as Array<[Answer[], number[]]>))('ends in one navigation to the way back: %j arriving as %j', async (answers, order) => {
    await concurrent(answers, order)

    expect(navigations).toEqual(['/martis/sso/azure/redirect'])
  })

  it.each(orders)('ends in one navigation to the login page when none carries the way back (arriving as %j)', async (...order) => {
    await concurrent(['bare', 'bare', 'bare'], order)

    expect(navigations).toEqual(['/martis/login?expired=1'])
  })

  it('does nothing for a 401 that comes after the way back was followed, a minute later too', async () => {
    answer(401, { message: 'Your session has ended.', sso_reauth_url: '/martis/sso/azure/redirect' })
    await expect(api.get('/api/tools')).rejects.toBeInstanceOf(ApiError)

    answer(401, {})
    await expect(api.get('/api/tools')).rejects.toBeInstanceOf(ApiError)
    answer(401, { sso_reauth_url: '/martis/sso/azure/redirect' })
    await expect(api.get('/api/tools')).rejects.toBeInstanceOf(ApiError)
    await vi.advanceTimersByTimeAsync(60_000)

    expect(navigations).toEqual(['/martis/sso/azure/redirect'])
  })

  it('follows the way back again when the browser restores the page from its cache', async () => {
    answer(401, { sso_reauth_url: '/martis/sso/azure/redirect' })
    await expect(api.get('/api/tools')).rejects.toBeInstanceOf(ApiError)

    const restored = new Event('pageshow')
    Object.defineProperty(restored, 'persisted', { value: true })
    window.dispatchEvent(restored)

    await expect(api.get('/api/tools')).rejects.toBeInstanceOf(ApiError)
    expect(navigations).toEqual(['/martis/sso/azure/redirect', '/martis/sso/azure/redirect'])
  })

  it('does not follow a way back that is not safe, and so does not stop the login redirect', async () => {
    answer(401, { sso_reauth_url: 'https://evil.example/martis/sso/azure/redirect' })
    await expect(api.get('/api/tools')).rejects.toBeInstanceOf(ApiError)
    answer(401, { sso_reauth_url: 'https://evil.example/martis/sso/azure/redirect' })
    await expect(api.get('/api/tools')).rejects.toBeInstanceOf(ApiError)
    await settle()

    expect(navigations).toEqual(['/martis/login?expired=1'])
  })
})
