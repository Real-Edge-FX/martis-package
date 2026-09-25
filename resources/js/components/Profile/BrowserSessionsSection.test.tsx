import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render } from '@testing-library/react'
import { BrowserSessionsSection } from './BrowserSessionsSection'

const get = vi.fn()

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (_k: string, o?: { defaultValue?: string }) => o?.defaultValue ?? _k,
    i18n: { language: 'en' },
  }),
}))
vi.mock('@/lib/api', () => ({ api: { get: (...args: unknown[]) => get(...args), delete: vi.fn() }, ApiError: class extends Error {} }))
vi.mock('@/contexts/ToastContext', () => ({ useToast: () => ({ addToast: vi.fn() }) }))

describe('BrowserSessionsSection — unsupported', () => {
  beforeEach(() => {
    get.mockReset()
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
