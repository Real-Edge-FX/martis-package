import i18n from 'i18next'
import { useTranslation } from 'react-i18next'

/**
 * The locale Martis formats dates and numbers in: the user's Martis
 * language, never the browser's (v2.1.0).
 *
 * Martis codes use an underscore (`pt_PT`); `Intl` wants a BCP 47 tag
 * (`pt-PT`) and throws a RangeError on the underscore form.
 */

/**
 * `pt_PT` → `pt-PT`. Undefined for an empty or invalid code, so `Intl`
 * falls back to the runtime default instead of throwing.
 */
export function toBcp47(locale?: string | null): string | undefined {
  const code = (locale ?? '').trim().replace(/_/g, '-')
  if (code === '') return undefined

  try {
    return Intl.getCanonicalLocales(code)[0]
  } catch {
    return undefined
  }
}

/** The active Martis locale as a BCP 47 tag, for code outside components. */
export function currentFormatLocale(): string | undefined {
  return toBcp47(i18n.language)
}

/**
 * The active Martis locale as a BCP 47 tag. `useTranslation()` subscribes
 * the component to `languageChanged`, so a preference switch re-formats it
 * without a reload. A test double of react-i18next may leave `i18n` out;
 * the singleton answers then.
 */
export function useFormatLocale(): string | undefined {
  const { i18n: active } = useTranslation()

  return toBcp47(active?.language ?? i18n.language)
}
