import { describe, it, expect } from 'vitest'
import { formatLocalDate, parseLocalDate } from './localDate'

describe('formatLocalDate', () => {
  it('formats a local-midnight Date as its LOCAL calendar day', () => {
    // The PrimeReact Calendar emits a local-midnight Date when the user picks a
    // day. The old code did `d.toISOString().split('T')[0]`, which drops a day
    // east of UTC (pick the 20th, store the 19th). formatLocalDate reads the
    // local calendar components, so the picked day is the stored day.
    expect(formatLocalDate(new Date(2026, 6, 20))).toBe('2026-07-20')
  })

  it('zero-pads month and day', () => {
    expect(formatLocalDate(new Date(2026, 0, 5))).toBe('2026-01-05')
    expect(formatLocalDate(new Date(2026, 11, 31))).toBe('2026-12-31')
  })
})

describe('parseLocalDate', () => {
  it('parses YYYY-MM-DD into a LOCAL calendar date (no UTC shift)', () => {
    const d = parseLocalDate('2026-07-20')
    expect(d).not.toBeNull()
    expect(d!.getFullYear()).toBe(2026)
    expect(d!.getMonth()).toBe(6) // July (0-indexed)
    expect(d!.getDate()).toBe(20)
  })

  it('round-trips a YYYY-MM-DD string unchanged, in any timezone', () => {
    for (const s of ['2026-07-20', '2026-01-01', '2026-12-31', '2026-03-09']) {
      expect(formatLocalDate(parseLocalDate(s)!)).toBe(s)
    }
  })

  it('returns null for empty, invalid, or non-string input', () => {
    expect(parseLocalDate('')).toBeNull()
    expect(parseLocalDate(null)).toBeNull()
    expect(parseLocalDate(undefined)).toBeNull()
    expect(parseLocalDate(42)).toBeNull()
    expect(parseLocalDate('not-a-date')).toBeNull()
  })

  it('returns null for an out-of-range YYYY-MM-DD instead of silently rolling over', () => {
    // JS `new Date(2026, 12, 40)` rolls over to a valid date; the round-trip
    // check rejects it so the field falls back to the raw string.
    expect(parseLocalDate('2026-13-40')).toBeNull()
    expect(parseLocalDate('2026-02-30')).toBeNull()
    expect(parseLocalDate('2026-00-10')).toBeNull()
  })

  it('does not mis-map a 0-99 year through the legacy Date offset', () => {
    // `new Date(99, …)` yields 1999; the round-trip check rejects the mismatch.
    expect(parseLocalDate('0099-06-15')).toBeNull()
  })

  it('falls back to the native parser for a full datetime string', () => {
    const d = parseLocalDate('2026-07-20T09:30:00Z')
    expect(d).not.toBeNull()
    expect(isNaN(d!.getTime())).toBe(false)
  })
})
