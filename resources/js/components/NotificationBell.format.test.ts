import { describe, it, expect } from 'vitest'
import { formatRelative } from './NotificationBell'

const t = (_key: string, fallback: string) => fallback

describe('formatRelative', () => {
  it('formats a notification older than a week in the given locale', () => {
    const iso = new Date(Date.now() - 30 * 24 * 3600 * 1000).toISOString()

    expect(formatRelative(iso, t, 'pt-PT')).toBe(new Date(iso).toLocaleDateString('pt-PT'))
    expect(formatRelative(iso, t, 'en-US')).toBe(new Date(iso).toLocaleDateString('en-US'))
  })
})
