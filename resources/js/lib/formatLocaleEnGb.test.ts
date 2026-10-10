import { describe, it, expect } from 'vitest'
import { toBcp47 } from './formatLocale'

describe('en_GB formatting (v2.10.0)', () => {
  it('formats a date as DD/MM/YYYY through the BCP 47 tag of the Martis code', () => {
    const tag = toBcp47('en_GB')
    const date = new Date(Date.UTC(2026, 9, 25, 12))

    expect(new Intl.DateTimeFormat(tag, { timeZone: 'UTC' }).format(date)).toBe('25/10/2026')
    expect(new Intl.DateTimeFormat(toBcp47('en'), { timeZone: 'UTC' }).format(date)).toBe('10/25/2026')
  })
})
