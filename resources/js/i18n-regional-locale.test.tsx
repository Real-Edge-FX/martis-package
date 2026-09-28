import { describe, it, expect, vi, beforeEach } from 'vitest'
import i18n from 'i18next'
import { config } from '@/lib/config'
import { getLocale, initI18n } from '@/lib/i18n'
import { currentFormatLocale } from '@/lib/formatLocale'

/*
 * A regional code such as `en_GB` in `martis.preferences.locales` translates
 * through `en` and formats with its region (docs/i18n.md). getLocale() used
 * to collapse `en_GB` and `en_US` to `en`, so i18next booted in `en` and the
 * first paint used US formats (PR #276 review).
 */

function bootWith(locale: string) {
  window.localStorage.clear()
  ;(config as { locale?: string }).locale = locale
}

describe('getLocale() keeps the saved code', () => {
  beforeEach(() => window.localStorage.clear())

  it.each(['en_GB', 'en_US', 'pt_PT', 'en'])('returns %s as saved', (locale) => {
    bootWith(locale)

    expect(getLocale()).toBe(locale)
  })

  it('reads the code the browser cached last', () => {
    bootWith('pt_PT')
    window.localStorage.setItem('martis-preferences', JSON.stringify({ locale: 'en_GB' }))

    expect(getLocale()).toBe('en_GB')
  })
})

describe('booting a user whose Martis locale is en_GB', () => {
  it('loads the en_GB bundle and formats en-GB from the first paint', async () => {
    bootWith('en_GB')
    const fetchSpy = vi.fn(async (_url: string) => new Response(JSON.stringify({ messages: { hello: 'Hello' } }), { status: 200 }))
    vi.stubGlobal('fetch', fetchSpy)

    await initI18n()

    expect(String(fetchSpy.mock.calls[0]?.[0])).toMatch(/\/api\/translations\/en_GB$/)
    expect(i18n.language).toBe('en_GB')
    expect(i18n.t('messages:hello')).toBe('Hello')
    expect(currentFormatLocale()).toBe('en-GB')
    expect(new Date(2026, 8, 27).toLocaleDateString(currentFormatLocale())).toBe('27/09/2026')

    vi.unstubAllGlobals()
  })
})
