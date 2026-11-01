import { afterEach, beforeEach, describe, it, expect, vi } from 'vitest'
import { formatRelative } from './NotificationBell'

const t = (_key: string, fallback: string) => fallback

// Fixed clock and literal expectations: the date is the one the locale
// prints, not a second call of the formatter the component uses.
describe('formatRelative', () => {
  beforeEach(() => {
    vi.useFakeTimers()
    vi.setSystemTime(new Date('2026-10-31T12:00:00Z'))
  })
  afterEach(() => vi.useRealTimers())

  it('formats a notification older than a week in the given locale', () => {
    const iso = '2026-10-01T12:00:00Z'

    expect(formatRelative(iso, t, 'pt-PT')).toBe('01/10/2026')
    expect(formatRelative(iso, t, 'en-US')).toBe('10/1/2026')
  })
})
