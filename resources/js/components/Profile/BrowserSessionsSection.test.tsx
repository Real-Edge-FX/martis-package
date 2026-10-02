import { describe, it, expect, vi, beforeEach } from 'vitest'
import { fireEvent, render, waitFor } from '@testing-library/react'
import { BrowserSessionsSection } from './BrowserSessionsSection'

const get = vi.fn()
const del = vi.fn()

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (_k: string, o?: { defaultValue?: string }) => o?.defaultValue ?? _k,
    i18n: { language: 'en' },
  }),
}))
vi.mock('@/lib/api', () => ({ api: { get: (...args: unknown[]) => get(...args), delete: (...args: unknown[]) => del(...args) }, ApiError: class extends Error {} }))
vi.mock('@/contexts/ToastContext', () => ({ useToast: () => ({ addToast: vi.fn() }) }))

describe('BrowserSessionsSection — unsupported', () => {
  beforeEach(() => {
    get.mockReset()
    del.mockReset()
  })

  it('shows the reason the server gives', async () => {
    get.mockResolvedValue({
      sessions: [],
      supported: false,
      driver: 'database',
      reason: 'The app signs in users of more than one table.',
    })

    const { findByText, queryByText } = render(<BrowserSessionsSection />)

    expect(await findByText('The app signs in users of more than one table.')).toBeTruthy()
    expect(queryByText('Browser-session management requires the database session driver.')).toBeNull()
  })

  it('falls back to the session driver hint without a reason', async () => {
    get.mockResolvedValue({ sessions: [], supported: false, driver: 'file' })

    const { findByText } = render(<BrowserSessionsSection />)

    expect(await findByText('Browser-session management requires the database session driver.')).toBeTruthy()
  })
})

describe('BrowserSessionsSection: opaque handles', () => {
  // The id of a session row is an opaque handle the server computed (v2.4.0),
  // never the `sessions.id` of the device: the revoke call hands it back as it
  // came, and nothing else of the row identifies it.
  const handleA = 'a'.repeat(64)
  const handleB = 'b/c+d=e'.padEnd(64, '9')

  beforeEach(() => {
    get.mockReset()
    del.mockReset()
    del.mockResolvedValue({ revoked: 1 })
    get.mockResolvedValue({
      supported: true,
      driver: 'database',
      sessions: [
        { id: handleA, ip_address: '10.0.0.1', user_agent: 'Chrome on Mac OS X', last_active: Math.floor(Date.now() / 1000), is_current: true },
        { id: handleB, ip_address: '10.0.0.2', user_agent: 'Safari on iPhone', last_active: Math.floor(Date.now() / 1000) - 60, is_current: false },
      ],
    })
  })

  it('revokes a device by the handle the server gave it, encoded as one path segment', async () => {
    const { findByLabelText } = render(<BrowserSessionsSection />)

    fireEvent.click(await findByLabelText('Revoke session'))

    await waitFor(() => expect(del).toHaveBeenCalledWith(`/api/profile/sessions/${encodeURIComponent(handleB)}`))
    expect(del).toHaveBeenCalledTimes(1)
  })

  it('offers no revoke button on the current device, so its handle is never sent', async () => {
    const { findAllByLabelText } = render(<BrowserSessionsSection />)

    expect(await findAllByLabelText('Revoke session')).toHaveLength(1)
  })
})
