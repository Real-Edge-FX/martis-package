import { useTranslation } from 'react-i18next'
import { config } from '@/lib/config'

/**
 * Resolve a copy string for the unauthenticated auth surfaces with the
 * priority used by Martis since v1.8.0:
 *
 *   1. `config.auth.copy.<page>.<key>` — explicit consumer override.
 *      Returned verbatim when set to a non-empty string.
 *   2. `t(translationKey, { defaultValue })` — bundled i18n. Returned
 *      when (1) is missing.
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

/**
 * Hook variant — call from inside an auth page component.
 *
 * @example
 *   const tCopy = useAuthCopy()
 *   const title = tCopy('login', 'title', 'login_title', 'Sign in to your workspace')
 */
export function useAuthCopy() {
  const { t } = useTranslation('auth')

  return function tCopy(
    page: AuthPage,
    key: AuthCopyKey,
    translationKey: string,
    defaultValue: string,
  ): string {
    const overrides = config.auth?.copy
    const pageOverrides = overrides?.[page] as Record<string, string | null | undefined> | undefined
    const override = pageOverrides?.[key]
    if (typeof override === 'string' && override.trim() !== '') {
      return override
    }
    return t(translationKey, { defaultValue })
  }
}
