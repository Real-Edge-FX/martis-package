import { describe, it, expect, vi } from 'vitest'
import { render } from '@testing-library/react'
import { BrowserSessionsSection } from './BrowserSessionsSection'

const get = vi.fn()

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (_k: string, o?: { defaultValue?: string }) => o?.defaultValue ?? _k,
    i18n: { language: 'pt_PT' },
  }),
}))
vi.mock('@/lib/api', () => ({ api: { get: (...args: unknown[]) => get(...args), delete: vi.fn() }, ApiError: class extends Error {} }))
vi.mock('@/contexts/ToastContext', () => ({ useToast: () => ({ addToast: vi.fn() }) }))

describe('BrowserSessionsSection with a pt_PT preference', () => {
  it('renders the last activity in pt-PT instead of throwing on the pt_PT code', async () => {
    get.mockResolvedValue({
      sessions: [{
        id: 'a',
        ip_address: '10.0.0.1',
        user_agent: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) Chrome/120.0',
        last_active: Math.floor(Date.now() / 1000) - 150,
        is_current: true,
      }],
      supported: true,
      driver: 'database',
    })
    const expected = new Intl.RelativeTimeFormat('pt-PT', { numeric: 'auto' }).format(-2, 'minute')

    const { findByText } = render(<BrowserSessionsSection />)

    expect(await findByText((content) => content.includes(expected))).toBeTruthy()
  })
})
