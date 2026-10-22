import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

// A session that expired and came back through the remember-me cookie is a
// new sign-in, so EnsureTwoFactorChallenge answers every API call
// 423 {"two_factor_required": true}. The SPA only checked the pending
// challenge on boot (AuthContext), so a page already open showed the 423 as
// "Resource not found". request() and uploadRequest() now leave for the
// challenge page, as they do for a 401 or a forced password change.

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

describe('the two-factor challenge signal', () => {
  it('sends a user with a pending challenge to the challenge page from request()', async () => {
    answer(423, { two_factor_required: true, message: 'Two-factor authentication is required.' })

    await expect(api.get('/api/resources/users')).rejects.toBeInstanceOf(ApiError)
    expect(location.href).toBe('/martis/2fa/challenge')
  })

  it('does the same from uploadRequest()', async () => {
    answer(423, { two_factor_required: true })

    await expect(api.upload('POST', '/api/resources/users', { name: 'Ada' })).rejects.toBeInstanceOf(ApiError)
    expect(location.href).toBe('/martis/2fa/challenge')
  })

  it('stays put on the challenge page itself', async () => {
    location.pathname = '/martis/2fa/challenge'
    location.href = 'http://localhost/martis/2fa/challenge'
    answer(423, { two_factor_required: true })

    await expect(api.get('/api/preferences')).rejects.toBeInstanceOf(ApiError)
    expect(location.href).toBe('http://localhost/martis/2fa/challenge')
  })

  it('ignores a 423 without the flag', async () => {
    answer(423, { message: 'The record is locked.' })

    await expect(api.get('/api/resources/users')).rejects.toBeInstanceOf(ApiError)
    expect(location.href).toBe('http://localhost/martis/resources/users')
  })
})
