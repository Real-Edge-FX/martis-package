import { forwardRef, useEffect, useImperativeHandle, useRef, useState } from 'react'
import { OverlayPanel } from 'primereact/overlaypanel'
import { useTranslation } from 'react-i18next'
import { SlidersHorizontalIcon, SunIcon, MoonIcon, MonitorIcon, CheckIcon, ArrowCounterClockwiseIcon } from '@phosphor-icons/react'
import { usePreferences, type AccentColor, type ThemeMode, type UiDensity } from '@/contexts/PreferencesContext'
import { config } from '@/lib/config'
import { loadLocale } from '@/lib/i18n'

/**
 * Task 07.1 ⭐ D2 — User preferences panel.
 *
 * Compact overlay anchored to a topbar icon. Exposes pickers
 * (theme, accent, density, language) + optional brand-color hex input
 * (⭐ D1, off by default) and an accessibility section with the
 * reduced-motion toggle (⭐ D3). Every change flows through
 * `usePreferences().update()` which is optimistic and server-synced.
 */

const ACCENT_SWATCHES: Array<{ key: AccentColor; label: string; color: string }> = [
  { key: 'martis', label: 'Martis', color: '#4F7BF9' },
  { key: 'blue', label: 'Blue', color: '#3B82F6' },
  { key: 'teal', label: 'Teal', color: '#14B8A6' },
  { key: 'violet', label: 'Violet', color: '#8B5CF6' },
  { key: 'amber', label: 'Amber', color: '#F59E0B' },
]

const THEME_OPTIONS: Array<{ key: ThemeMode; labelKey: string; fallback: string; icon: typeof SunIcon }> = [
  { key: 'dark', labelKey: 'theme_dark', fallback: 'Dark', icon: MoonIcon },
  { key: 'light', labelKey: 'theme_light', fallback: 'Light', icon: SunIcon },
  { key: 'system', labelKey: 'theme_system', fallback: 'System', icon: MonitorIcon },
]

const DENSITY_OPTIONS: Array<{ key: UiDensity; labelKey: string; fallback: string }> = [
  { key: 'comfortable', labelKey: 'density_comfortable', fallback: 'Comfortable' },
  { key: 'dense', labelKey: 'density_dense', fallback: 'Dense' },
]

/** Baseline labels for the locales the package ships translations for.
 *  Merged with `config.preferences.localeLabels` so apps can name any extra
 *  locale they add via config without touching the package source. */
const BUILTIN_LOCALE_LABELS: Record<string, string> = {
  en: 'English',
  pt_PT: 'Português (PT)',
  pt_BR: 'Português (BR)',
}

/** Accept #RGB, #RRGGBB, or #RRGGBBAA. Returns the 6/8-char normalised form, or null. */
function normaliseHex(raw: string): string | null {
  const v = raw.trim().toLowerCase()
  const m3 = v.match(/^#([0-9a-f])([0-9a-f])([0-9a-f])$/)
  if (m3) return `#${m3[1]}${m3[1]}${m3[2]}${m3[2]}${m3[3]}${m3[3]}`
  if (/^#([0-9a-f]{6}|[0-9a-f]{8})$/.test(v)) return v
  return null
}

export interface PreferencesMenuHandle {
  hide: () => void
}

export const PreferencesMenu = forwardRef<PreferencesMenuHandle>(function PreferencesMenu(_props, handleRef) {
  const overlayRef = useRef<OverlayPanel>(null)
  const triggerRef = useRef<HTMLButtonElement>(null)
  useImperativeHandle(handleRef, () => ({
    hide: () => overlayRef.current?.hide(),
  }), [])

  // Defensive outside-click handler — PrimeReact's built-in `dismissable`
  // misfires when multiple overlay portals coexist (e.g. the avatar Menu).
  // Listening on the document and checking against both the panel and the
  // trigger guarantees the panel always closes when focus moves elsewhere.
  useEffect(() => {
    const onDocClick = (e: MouseEvent) => {
      const target = e.target as Node | null
      if (!target) return
      const panel = document.querySelector('.martis-preferences-panel')
      if (panel?.contains(target)) return
      if (triggerRef.current?.contains(target)) return
      overlayRef.current?.hide()
    }
    document.addEventListener('mousedown', onDocClick)
    return () => document.removeEventListener('mousedown', onDocClick)
  }, [])
  const { prefs, meta, update, reset, enabled } = usePreferences()
  const { t } = useTranslation('messages')
  const [brandColorInput, setBrandColorInput] = useState(prefs.brandColor ?? '')

  if (!enabled) return null

  const allowBrandColor = config.preferences?.allowBrandColor === true
  const locales = meta?.locales ?? ['en', 'pt_PT', 'pt_BR']
  const localeLabels = { ...BUILTIN_LOCALE_LABELS, ...(config.preferences?.localeLabels ?? {}) }

  const onThemePick = (theme: ThemeMode) => { void update({ theme }) }
  const onAccentPick = (accent: AccentColor) => {
    const patch: { accent: AccentColor; brandColor?: string | null } = { accent }
    if (accent !== 'custom' && prefs.brandColor) {
      patch.brandColor = null
      setBrandColorInput('')
    }
    void update(patch)
  }
  const onDensityPick = (density: UiDensity) => { void update({ density }) }
  const onLocalePick = async (locale: string) => {
    // Order matters: the PUT must settle before we re-fetch React Query
    // caches, otherwise the refetched `/api/navigation` reads the *previous*
    // locale from the DB and caches it back under the new language.
    await update({ locale })
    await loadLocale(locale)
  }
  const onReducedMotionToggle = () => { void update({ reducedMotion: !prefs.reducedMotion }) }

  const brandColorValid = brandColorInput === '' || normaliseHex(brandColorInput) !== null
  const onBrandColorChange = (raw: string) => {
    setBrandColorInput(raw)
    const norm = normaliseHex(raw)
    if (norm) void update({ brandColor: norm, accent: 'custom' })
  }
  const onBrandColorClear = () => {
    setBrandColorInput('')
    void update({ brandColor: null, accent: prefs.accent === 'custom' ? 'martis' : prefs.accent })
  }

  return (
    <>
      <button
        ref={triggerRef}
        type="button"
        aria-label={t('preferences', 'Preferences')}
        onClick={(e) => overlayRef.current?.toggle(e)}
        className="inline-flex items-center justify-center rounded-md border p-2 text-[color:var(--martis-text-muted)] hover:bg-[color:var(--martis-hover)] hover:text-[color:var(--martis-text)]"
        style={{ borderColor: 'transparent' }}
        title={t('preferences', 'Preferences')}
      >
        <SlidersHorizontalIcon size={18} />
      </button>

      <OverlayPanel
        ref={overlayRef}
        className="martis-preferences-panel"
        showCloseIcon={false}
        style={{
          width: 320,
          backgroundColor: 'var(--martis-surface)',
          border: '1px solid var(--martis-border)',
          borderRadius: 'var(--martis-radius-lg)',
          color: 'var(--martis-text)',
          boxShadow: 'var(--martis-shadow-md)',
        }}
      >
        <div className="flex flex-col gap-4 p-4">
          <div className="flex items-center justify-between">
            <div className="text-sm font-semibold">{t('preferences', 'Preferences')}</div>
            <button
              type="button"
              onClick={() => void reset()}
              className="inline-flex items-center gap-1 text-xs hover:underline"
              style={{ color: 'var(--martis-text-muted)' }}
              title={t('reset_to_defaults', 'Reset to defaults')}
            >
              <ArrowCounterClockwiseIcon size={12} />
              {t('reset', 'Reset')}
            </button>
          </div>

          {/* Theme */}
          <Section label={t('theme', 'Theme')}>
            <div className="flex overflow-hidden rounded-md border" style={{ borderColor: 'var(--martis-border)' }}>
              {THEME_OPTIONS.map(({ key, labelKey, fallback, icon: Icon }) => (
                <button
                  key={key}
                  type="button"
                  onClick={() => onThemePick(key)}
                  className="flex flex-1 items-center justify-center gap-1.5 px-2 py-1.5 text-xs"
                  style={{
                    backgroundColor: prefs.theme === key ? 'var(--martis-accent-bg-light)' : 'transparent',
                    color: prefs.theme === key ? 'var(--martis-accent)' : 'var(--martis-text-muted)',
                    borderRight: '1px solid var(--martis-border)',
                  }}
                >
                  <Icon size={14} />
                  {t(labelKey, fallback)}
                </button>
              ))}
            </div>
          </Section>

          {/* Accent */}
          <Section label={t('accent', 'Accent')}>
            <div className="flex items-center gap-2">
              {ACCENT_SWATCHES.map(({ key, label, color }) => (
                <button
                  key={key}
                  type="button"
                  onClick={() => onAccentPick(key)}
                  aria-label={label}
                  title={label}
                  className="relative h-7 w-7 rounded-full transition-transform hover:scale-110"
                  style={{
                    backgroundColor: color,
                    boxShadow: prefs.accent === key ? '0 0 0 2px var(--martis-surface), 0 0 0 4px var(--martis-accent)' : 'none',
                  }}
                >
                  {prefs.accent === key && !prefs.brandColor && (
                    <CheckIcon size={12} weight="bold" color="#fff" style={{ position: 'absolute', top: '50%', left: '50%', transform: 'translate(-50%, -50%)' }} />
                  )}
                </button>
              ))}
            </div>
            {allowBrandColor && (
              <>
                <div className="mt-2 flex items-center gap-2">
                  <input
                    type="text"
                    value={brandColorInput}
                    onChange={(e) => onBrandColorChange(e.target.value)}
                    placeholder="#4f7bf9"
                    className="flex-1 rounded-md border px-2 py-1 font-mono text-xs"
                    style={{
                      borderColor: brandColorValid ? 'var(--martis-border)' : 'var(--martis-danger)',
                      backgroundColor: 'var(--martis-input-bg)',
                      color: 'var(--martis-text)',
                    }}
                  />
                  {prefs.brandColor && (
                    <button
                      type="button"
                      onClick={onBrandColorClear}
                      className="rounded-md px-2 py-1 text-xs"
                      style={{ color: 'var(--martis-text-muted)' }}
                      title={t('clear_brand_color', 'Clear brand color')}
                    >
                      ×
                    </button>
                  )}
                </div>
                <p className="mt-1 text-[10px]" style={{ color: brandColorValid ? 'var(--martis-text-muted)' : 'var(--martis-danger-text)' }}>
                  {brandColorValid
                    ? t('brand_color_help', 'Custom hex overrides the accent (⭐ D1)')
                    : t('brand_color_invalid', 'Use #RGB, #RRGGBB or #RRGGBBAA')}
                </p>
              </>
            )}
          </Section>

          {/* Density */}
          <Section label={t('density', 'Density')}>
            <div className="flex overflow-hidden rounded-md border" style={{ borderColor: 'var(--martis-border)' }}>
              {DENSITY_OPTIONS.map(({ key, labelKey, fallback }) => (
                <button
                  key={key}
                  type="button"
                  onClick={() => onDensityPick(key)}
                  className="flex flex-1 items-center justify-center px-2 py-1.5 text-xs"
                  style={{
                    backgroundColor: prefs.density === key ? 'var(--martis-accent-bg-light)' : 'transparent',
                    color: prefs.density === key ? 'var(--martis-accent)' : 'var(--martis-text-muted)',
                    borderRight: '1px solid var(--martis-border)',
                  }}
                >
                  {t(labelKey, fallback)}
                </button>
              ))}
            </div>
          </Section>

          {/* Language */}
          <Section label={t('language', 'Language')}>
            <select
              value={prefs.locale}
              onChange={(e) => { void onLocalePick(e.target.value) }}
              className="w-full rounded-md border px-2 py-1.5 text-xs"
              style={{
                borderColor: 'var(--martis-border)',
                backgroundColor: 'var(--martis-input-bg)',
                color: 'var(--martis-text)',
              }}
            >
              {locales.map((l) => (
                <option key={l} value={l}>{localeLabels[l] ?? l}</option>
              ))}
            </select>
          </Section>

          {/* Accessibility — reduced motion sits apart from theme/accent/density. */}
          <Section label={t('accessibility', 'Accessibility')}>
            <label className="flex cursor-pointer items-center justify-between gap-2 text-xs">
              <span style={{ color: 'var(--martis-text)' }}>{t('reduced_motion', 'Reduced motion')}</span>
              <input
                type="checkbox"
                checked={prefs.reducedMotion}
                onChange={onReducedMotionToggle}
                style={{ accentColor: 'var(--martis-accent)' }}
              />
            </label>
          </Section>

          {meta?.preset && (
            <div
              className="rounded-md border px-2 py-1.5 text-[10px]"
              style={{
                borderColor: 'var(--martis-accent)',
                backgroundColor: 'var(--martis-accent-bg-light)',
                color: 'var(--martis-accent)',
              }}
            >
              {t('applied_preset', 'Preset applied')}: <strong>{meta.preset}</strong>
            </div>
          )}
        </div>
      </OverlayPanel>
    </>
  )
})

function Section({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="flex flex-col gap-1.5">
      <div className="text-[10px] font-semibold uppercase tracking-wider" style={{ color: 'var(--martis-text-muted)' }}>
        {label}
      </div>
      {children}
    </div>
  )
}
