import { useTranslation } from 'react-i18next'
import i18n from '@/lib/i18n'
import { config } from '@/lib/config'

/**
 * Resolve a copy string for the unauthenticated auth surfaces with the
 * priority used by Martis since v1.8.5:
 *
 *   1. `config.auth.copy.<page>.<key>` — explicit consumer override.
 *      THREE accepted shapes:
 *      - non-empty string  → returned verbatim
 *      - `Record<locale, string>` → resolved against the active i18n
 *        language (i18next current language → app default → 'en' →
 *        first available)
 *      - null / undefined  → falls through to (2)
 *   2. `t(translationKey, { defaultValue })` — bundled i18n.
 *
 * The translation key is the legacy one (e.g. `login_title`, `login_sub`,
 * `login_sub_v2`) so existing translations published by consumers
 * continue to work. Adding a config override is purely additive.
 *
 * Used by Login / Register / ForgotPassword / ResetPassword.
 */

export type AuthPage = 'login' | 'register' | 'forgot_password' | 'reset_password'

export type AuthCopyKey =
  | 'title'
  | 'subtitle'
  | 'subtitle_with_sso'

type AuthCopyValue = string | Record<string, string> | null | undefined

function pickFromMap(map: Record<string, string>, locale: string): string | null {
  // Resolution order: exact match → locale prefix (en_US → en) → 'en' → first non-empty.
  const tryLocale = (l: string | undefined) => {
    if (!l) return null
    const v = map[l]
    return typeof v === 'string' && v.trim() !== '' ? v : null
  }

  const direct = tryLocale(locale)
  if (direct !== null) return direct

  const baseLocale = locale.includes('_') || locale.includes('-')
    ? locale.split(/[_-]/)[0]
    : null
  const baseHit = tryLocale(baseLocale ?? undefined)
  if (baseHit !== null) return baseHit

  const enHit = tryLocale('en')
  if (enHit !== null) return enHit

  for (const candidate of Object.values(map)) {
    if (typeof candidate === 'string' && candidate.trim() !== '') {
      return candidate
    }
  }
  return null
}

/**
 * Hook variant — call from inside an auth page component.
 *
 * @example
 *   const tCopy = useAuthCopy()
 *   const title = tCopy('login', 'title', 'login_title', 'Sign in to your workspace')
 */
export function useAuthCopy() {
  // useTranslation tracks language changes — re-renders fire when the
  // user flips the language picker, so the consumer's copy switches
  // without a hard refresh.
  const { t, i18n: i18nInstance } = useTranslation('auth')

  return function tCopy(
    page: AuthPage,
    key: AuthCopyKey,
    translationKey: string,
    defaultValue: string,
  ): string {
    const overrides = config.auth?.copy
    const pageOverrides = overrides?.[page] as Record<string, AuthCopyValue> | undefined
    const override = pageOverrides?.[key]

    if (typeof override === 'string' && override.trim() !== '') {
      return override
    }
    if (override && typeof override === 'object') {
      const locale = i18nInstance?.language || i18n.language || 'en'
      const fromMap = pickFromMap(override as Record<string, string>, locale)
      if (fromMap !== null) return fromMap
    }
    return t(translationKey, { defaultValue })
  }
}
