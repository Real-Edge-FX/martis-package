import { describe, it, expect, beforeEach, afterEach } from 'vitest'
import { formatItemCount } from '@/lib/navigation'

/**
 * Tests for the count-badge formatter introduced in v1.8.0.
 *
 * The default threshold is 10_000. Values below render with locale
 * thousand separators; values at or above use Intl compact notation.
 *
 * The threshold is read from `window.MartisConfig.navigation.countCompactThreshold`
 * so the tests mutate that field per case.
 */
describe('formatItemCount', () => {
  type W = Window & {
    MartisConfig?: { navigation?: { countCompactThreshold?: number | null } }
  }

  beforeEach(() => {
    ;(window as unknown as W).MartisConfig = { navigation: {} }
  })

  afterEach(() => {
    delete (window as unknown as W).MartisConfig
  })

  it('uses full digits below the default threshold', () => {
    // The runtime locale on CI may differ, so we just check the digit
    // sequence is preserved (separator may be , or .)
    const out = formatItemCount(1234)
    expect(out.replace(/[\.,\s]/g, '')).toBe('1234')
    expect(out).not.toMatch(/[KM]/)
  })

  it('shows 9999 in full digits (just below threshold)', () => {
    const out = formatItemCount(9999)
    expect(out.replace(/[\.,\s]/g, '')).toBe('9999')
  })

  it('compacts 10000 to a K-suffixed string', () => {
    const out = formatItemCount(10_000)
    expect(out).toMatch(/10\s?K/i)
  })

  it('compacts 123456 to ~123.5K (one decimal of precision)', () => {
    const out = formatItemCount(123_456)
    expect(out).toMatch(/123[\.,]?5?\s?K/i)
  })

  it('compacts 1234567 to ~1.2M (one decimal at 1M+)', () => {
    const out = formatItemCount(1_234_567)
    expect(out).toMatch(/1[\.,]2\s?M/i)
  })

  it('compacts 25_000_000 to 25M (no decimal still applies; 25.0M would be .0)', () => {
    const out = formatItemCount(25_000_000)
    expect(out).toMatch(/25\s?M/i)
  })

  it('respects a custom threshold from MartisConfig', () => {
    // Threshold 1000 forces compaction earlier — 999 still full digits,
    // 1500 already compact (Intl needs ≥ 1000 to start using "K", so a
    // threshold below that has no visible effect).
    ;(window as unknown as W).MartisConfig = {
      navigation: { countCompactThreshold: 1000 },
    }
    expect(formatItemCount(999).replace(/[\.,\s]/g, '')).toBe('999')
    expect(formatItemCount(1500)).toMatch(/1[\.,]5\s?K/i)
  })

  it('disables compaction when threshold is explicitly null', () => {
    ;(window as unknown as W).MartisConfig = {
      navigation: { countCompactThreshold: null },
    }
    const out = formatItemCount(5_000_000)
    // Whatever locale formatter is in use, the digit count must be preserved
    expect(out.replace(/[\.,\s]/g, '')).toBe('5000000')
    expect(out).not.toMatch(/[KM]/)
  })

  it('returns 0 unchanged', () => {
    expect(formatItemCount(0)).toBe('0')
  })
})
