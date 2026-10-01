import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

// The SPA leaves the page for the forced password change page when the
// server answers 409 {"password_change_required": true} (v2.3.0), from
// request() and uploadRequest() alike, and never loops on the page itself.

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

describe('the forced password change signal', () => {
  it('sends a held user to the change page from request()', async () => {
    answer(409, { password_change_required: true, message: 'Password change required.' })

    await expect(api.get('/api/tools')).rejects.toBeInstanceOf(ApiError)
    expect(location.href).toBe('/martis/password/change')
  })

  it('does the same from uploadRequest()', async () => {
    answer(409, { password_change_required: true, message: 'Password change required.' })

    await expect(api.upload('POST', '/api/resources/users', { name: 'Ada' })).rejects.toBeInstanceOf(ApiError)
    expect(location.href).toBe('/martis/password/change')
  })

  it('stays put on the change page itself', async () => {
    location.pathname = '/martis/password/change'
    location.href = 'http://localhost/martis/password/change'
    answer(409, { password_change_required: true })

    await expect(api.get('/api/tools')).rejects.toBeInstanceOf(ApiError)
    expect(location.href).toBe('http://localhost/martis/password/change')
  })

  it('ignores a 409 without the flag', async () => {
    answer(409, { message: 'The record changed.' })

    await expect(api.get('/api/tools')).rejects.toBeInstanceOf(ApiError)
    expect(location.href).toBe('http://localhost/martis/resources/users')
  })

  it('sends an unverified user to the notice from uploadRequest() too', async () => {
    answer(409, { message: 'Your email address is not verified.' })

    await expect(api.upload('POST', '/api/resources/users', { name: 'Ada' })).rejects.toBeInstanceOf(ApiError)
    expect(location.href).toBe('/martis/email/verify')
  })
})
