import { describe, it, expect, vi, beforeEach } from 'vitest'
import { act } from 'react'
import { render, waitFor } from '@testing-library/react'

/*
 * After the boot, PreferencesProvider reconciles i18next with the saved
 * locale through loadLocale(), which shows the full-screen switching overlay
 * and refetches every query. A user whose saved locale is a regional code
 * (`en_GB`) must boot in that code, so the reconcile has nothing to do on a
 * page load (PR #276 review).
 */

const loadLocale = vi.fn(async (_locale: string) => {})
let savedLocale = 'en_GB'

vi.mock('@/lib/i18n', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/i18n')>()
  return { ...actual, loadLocale: (locale: string) => loadLocale(locale) }
})
vi.mock('@/contexts/AuthContext', () => ({
  useAuth: () => ({ user: { id: 7, name: 'Ada', email: 'ada@example.com' }, isLoading: false }),
}))
vi.mock('@/lib/api', () => ({
  api: {
    get: vi.fn(async () => ({ data: { locale: savedLocale }, meta: {} })),
    put: vi.fn(async () => ({ data: {}, meta: {} })),
  },
}))

import i18n from 'i18next'
import { getLocale } from '@/lib/i18n'
import { config } from '@/lib/config'
import { PreferencesProvider } from '@/contexts/PreferencesContext'

async function bootAs(saved: string, serverSaved = saved): Promise<void> {
  window.localStorage.clear()
  savedLocale = serverSaved
  ;(config as { locale?: string }).locale = saved
  // What initI18n() does: lng = getLocale().
  await i18n.init({ lng: getLocale(), resources: {} })
  await act(async () => {
    render(<PreferencesProvider><div /></PreferencesProvider>)
  })
}

async function settle(): Promise<void> {
  await act(async () => {
    await new Promise((resolve) => setTimeout(resolve, 50))
  })
}

describe('PreferencesProvider reconcile on a page load', () => {
  beforeEach(() => loadLocale.mockClear())

  it.each(['en_GB', 'en_US', 'en', 'pt_PT'])('does not re-switch a user whose saved locale is %s', async (locale) => {
    await bootAs(locale)
    await settle()

    expect(i18n.language).toBe(locale)
    expect(loadLocale).not.toHaveBeenCalled()
  })

  it('still switches when the server holds another locale than the shell booted with', async () => {
    await bootAs('en', 'pt_PT')

    await waitFor(() => expect(loadLocale).toHaveBeenCalledWith('pt_PT'))
  })
})
