import { useTranslation } from 'react-i18next'
import { MoonIcon, SunIcon, MonitorIcon, TranslateIcon } from '@phosphor-icons/react'
import { usePreferencesOptional, type ThemeMode } from '@/contexts/PreferencesContext'
import { config } from '@/lib/config'
import { loadLocale } from '@/lib/i18n'

/** Built-in labels for the locales Martis ships translations for.
 *  Merged with `config.preferences.localeLabels` so consumers can name
 *  any extra locale they add without touching the package source. */
const BUILTIN_LOCALE_LABELS: Record<string, string> = {
  en: 'English',
  pt_PT: 'Português (PT)',
  pt_BR: 'Português (BR)',
}

const THEME_ORDER: ThemeMode[] = ['dark', 'light', 'system']

/**
 * Compact guest-mode controls rendered in the top-right of every auth
 * surface (Login, Register, 2FA challenge, error pages).
 *
 * Exposes only the two knobs a not-yet-authenticated user might want to
 * change: theme cycle (dark → light → system) and locale picker. Any
 * other preference lives in the full Preferences panel available once
 * the user signs in.
 *
 * `usePreferences().update()` is optimistic and tolerant of the
 * unauthenticated state — the PUT will fail silently, the DOM still
 * applies the change, and localStorage caches it until the user logs in.
 */
export function AuthControls() {
  // Use the optional variant so AuthControls renders cleanly inside
  // test surfaces (or any detached tree) that don't wrap the full
  // PreferencesProvider. Without this guard the 2FA challenge / error
  // pages would crash whenever `<PreferencesProvider>` is absent.
  const ctx = usePreferencesOptional()
  const { t } = useTranslation('messages')

  if (!ctx || !ctx.enabled) return null
  const { prefs, update, meta } = ctx

  // Per-surface visibility toggles from `martis.auth.controls`. Both
  // default to true so existing installs keep the current behaviour.
  const controls = config.auth?.controls ?? {}
  const showTheme = controls.theme !== false
  const showLocale = controls.locale !== false

  // Whole strip hides when both knobs are off — avoids rendering an
  // empty padded container in the corner.
  if (!showTheme && !showLocale) return null

  const availableLocales = meta?.locales ?? ['en', 'pt_PT', 'pt_BR']
  const localeLabels = { ...BUILTIN_LOCALE_LABELS, ...(config.preferences?.localeLabels ?? {}) }

  const nextThemeIndex = (THEME_ORDER.indexOf(prefs.theme) + 1) % THEME_ORDER.length
  const nextTheme = THEME_ORDER[nextThemeIndex]!
  const themeIcon =
    prefs.theme === 'dark' ? <MoonIcon size={16} /> :
    prefs.theme === 'light' ? <SunIcon size={16} /> :
    <MonitorIcon size={16} />
  const themeLabel =
    prefs.theme === 'dark' ? t('theme_dark', { defaultValue: 'Dark' }) :
    prefs.theme === 'light' ? t('theme_light', { defaultValue: 'Light' }) :
    t('theme_system', { defaultValue: 'System' })

  const onThemeCycle = () => { void update({ theme: nextTheme }) }
  const onLocalePick = async (locale: string) => {
    await update({ locale })
    await loadLocale(locale)
  }

  return (
    <div className="martis-auth-controls" aria-label={t('preferences', { defaultValue: 'Preferences' })}>
      {showTheme && (
        <button
          type="button"
          onClick={onThemeCycle}
          className="martis-auth-control"
          aria-label={`${t('theme', { defaultValue: 'Theme' })}: ${themeLabel}`}
          title={`${t('theme', { defaultValue: 'Theme' })}: ${themeLabel}`}
        >
          {themeIcon}
        </button>
      )}
      {showLocale && (
        <label className="martis-auth-control martis-auth-control-select">
          <TranslateIcon size={16} aria-hidden="true" />
          <select
            value={prefs.locale}
            onChange={(e) => void onLocalePick(e.target.value)}
            aria-label={t('language', { defaultValue: 'Language' })}
          >
            {availableLocales.map((l) => (
              <option key={l} value={l}>{localeLabels[l] ?? l}</option>
            ))}
          </select>
        </label>
      )}
    </div>
  )
}
