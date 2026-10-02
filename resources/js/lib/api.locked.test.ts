import { afterEach, describe, expect, it, vi } from 'vitest'

// A data endpoint that answers 403 with the lock payload of a soft-locked
// entity (`SoftGate::refusal()`, v2.4.0) raises `martis:locked` on the window,
// from request() and uploadRequest() alike, so the gate modal opens whatever
// the user was doing (a direct visit to a locked resource, a picker).

vi.mock('@/lib/config', () => ({ config: { auth: {} }, BASE_PATH: '/martis', API_BASE_URL: 'http://localhost/martis' }))

import { api, ApiError } from '@/lib/api'
import { LOCKED_EVENT } from '@/lib/lockEvent'

const LOCK = { reason: 'plan:pro', modal: { title: 'Pro feature', message: 'Upgrade to unlock.' } }

function answer(status: number, body: unknown): void {
  vi.stubGlobal('fetch', vi.fn(async () => new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })))
}

function listen(): { locks: unknown[]; stop: () => void } {
  const locks: unknown[] = []
  const handler = (event: Event) => locks.push((event as CustomEvent).detail)
  window.addEventListener(LOCKED_EVENT, handler)
  return { locks, stop: () => window.removeEventListener(LOCKED_EVENT, handler) }
}

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('the soft lock signal', () => {
  it('announces the lock of a 403 locked response from request(), and still rejects', async () => {
    answer(403, { message: 'This feature is locked for your account.', errors: [], locked: true, lock: LOCK })
    const { locks, stop } = listen()

    await expect(api.get('/api/resources/reports')).rejects.toBeInstanceOf(ApiError)

    stop()
    expect(locks).toEqual([LOCK])
  })

  it('announces it from uploadRequest() too', async () => {
    answer(403, { message: 'Locked.', errors: [], locked: true, lock: LOCK })
    const { locks, stop } = listen()

    await expect(api.upload('POST', '/api/resources/reports', { name: 'Ada' })).rejects.toBeInstanceOf(ApiError)

    stop()
    expect(locks).toEqual([LOCK])
  })

  it('stays silent on a plain 403', async () => {
    answer(403, { message: 'This action is unauthorized.', errors: [] })
    const { locks, stop } = listen()

    await expect(api.get('/api/resources/reports')).rejects.toBeInstanceOf(ApiError)

    stop()
    expect(locks).toEqual([])
  })

  it('stays silent when the locked flag comes with another status', async () => {
    answer(200, { locked: true, lock: LOCK })
    const { locks, stop } = listen()

    await api.get('/api/tools/pro')

    stop()
    expect(locks).toEqual([])
  })
})
