import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'

// resolvePickerLocales precedence: authenticated meta.locales →
// pre-login window.MartisConfig.preferences.locales → bundled default.
// `config` is captured from window.MartisConfig at module load, so each
// case resets modules and re-imports with the desired bootstrap.

describe('resolvePickerLocales', () => {
  const originalConfig = (window as unknown as { MartisConfig?: unknown }).MartisConfig

  beforeEach(() => {
    vi.resetModules()
  })

  afterEach(() => {
    ;(window as unknown as { MartisConfig?: unknown }).MartisConfig = originalConfig
  })

  async function load(bootstrap: unknown) {
    ;(window as unknown as { MartisConfig?: unknown }).MartisConfig = bootstrap
    return import('./config')
  }

  it('prefers authenticated meta.locales when present', async () => {
    const { resolvePickerLocales } = await load({ preferences: { locales: ['en', 'pt_PT', 'pt_BR'] } })
    // meta wins even over a broader configured list.
    expect(resolvePickerLocales(['en', 'pt_PT'])).toEqual(['en', 'pt_PT'])
  })

  it('falls back to preferences.locales when meta is null (the login case)', async () => {
    const { resolvePickerLocales } = await load({ preferences: { locales: ['en', 'pt_PT'] } })
    expect(resolvePickerLocales(null)).toEqual(['en', 'pt_PT'])
    expect(resolvePickerLocales(undefined)).toEqual(['en', 'pt_PT'])
  })

  it('ignores an empty meta list and uses preferences.locales', async () => {
    const { resolvePickerLocales } = await load({ preferences: { locales: ['en', 'pt_PT'] } })
    expect(resolvePickerLocales([])).toEqual(['en', 'pt_PT'])
  })

  it('falls back to the bundled three when neither meta nor preferences.locales is set', async () => {
    const { resolvePickerLocales, BUNDLED_LOCALES } = await load({ preferences: {} })
    expect(resolvePickerLocales(null)).toEqual(BUNDLED_LOCALES)
    expect(resolvePickerLocales(null)).toEqual(['en', 'pt_PT', 'pt_BR'])
  })

  it('falls back to bundled when preferences.locales is an empty array', async () => {
    const { resolvePickerLocales } = await load({ preferences: { locales: [] } })
    expect(resolvePickerLocales(null)).toEqual(['en', 'pt_PT', 'pt_BR'])
  })
})
