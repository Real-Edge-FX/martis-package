import { type ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import { config } from '@/lib/config'
import { AuthControls } from '@/components/auth/AuthControls'
import bundledLogo from '@images/logo.png'

interface AuthFrameProps {
  /** Main card content — title, form, toggles, buttons. */
  children: ReactNode
  /** Optional override for the card width (default 400px via the CSS class). */
  width?: number | string
}

/**
 * Shared shell for unauthenticated pages — Login, Register, 2FA
 * challenge, and the three error screens.
 *
 * The brand row at the top of the card resolves like this:
 *   1. `config.logo` — full horizontal lockup. When set, the lockup
 *      renders alone (no wordmark next to it, since most consumer
 *      lockups already include the wordmark).
 *   2. `config.icon` — small square icon. Renders alongside
 *      `config.brand` as `[icon] {Brand}` so the consumer can ship
 *      just an icon and let Martis compose the row.
 *   3. Bundled Martis logo (the lockup) — fallback when neither is set.
 *
 * Guest-mode theme and language controls sit in the top-right, so
 * visitors can switch before signing in. Footer reads
 * `config.footer.text` if set, otherwise the bundled translation.
 */
export function AuthFrame({ children, width }: AuthFrameProps) {
  const { t } = useTranslation('navigation')
  const brand = config.brand ?? 'Martis'
  const footerText = config.footer?.enabled === false
    ? null
    : (config.footer?.text ?? t('footer_default', { brand, defaultValue: '© {{brand}} · Powered by Martis' }))
  const cardStyle = width !== undefined ? { maxWidth: typeof width === 'number' ? `${width}px` : width } : undefined

  // v1.7.0 — theme-aware variants. When only one of light/dark is set
  // we reuse it for the missing slot. The DOM ships both images and
  // CSS hides one based on `<html data-theme>` for an instant toggle.
  const logoLight = config.logo ?? config.logoDark ?? null
  const logoDark = config.logoDark ?? config.logo ?? null
  const iconLight = config.icon ?? config.iconDark ?? null
  const iconDark = config.iconDark ?? config.icon ?? null

  let mode: 'logo' | 'icon' | 'bundled' = 'bundled'
  let lightSrc: string = bundledLogo
  let darkSrc: string = bundledLogo
  if (logoLight) {
    mode = 'logo'
    lightSrc = logoLight
    darkSrc = logoDark ?? logoLight
  } else if (iconLight) {
    mode = 'icon'
    lightSrc = iconLight
    darkSrc = iconDark ?? iconLight
  }
  const sameVariant = lightSrc === darkSrc

  return (
    <div className="martis-auth-frame">
      <div className="martis-auth-bg" aria-hidden="true" />
      <AuthControls />
      <div className="martis-auth-card" style={cardStyle}>
        <div className="martis-auth-brand" data-mode={mode}>
          {mode === 'icon' ? (
            <>
              {sameVariant ? (
                <img
                  src={lightSrc}
                  alt=""
                  aria-hidden="true"
                  style={{ height: '2rem', width: 'auto' }}
                />
              ) : (
                <>
                  <img
                    src={lightSrc}
                    alt=""
                    aria-hidden="true"
                    className="martis-brand-img--light"
                    style={{ height: '2rem', width: 'auto' }}
                  />
                  <img
                    src={darkSrc}
                    alt=""
                    aria-hidden="true"
                    className="martis-brand-img--dark"
                    style={{ height: '2rem', width: 'auto' }}
                  />
                </>
              )}
              <span style={{ fontSize: '1.5rem', fontWeight: 600, marginLeft: '0.5rem' }}>
                {brand}
              </span>
            </>
          ) : sameVariant ? (
            <img src={lightSrc} alt={brand} />
          ) : (
            <>
              <img
                src={lightSrc}
                alt={brand}
                className="martis-brand-img--light"
              />
              <img
                src={darkSrc}
                alt={brand}
                className="martis-brand-img--dark"
                aria-hidden="true"
              />
            </>
          )}
        </div>
        {children}
      </div>
      {footerText && (
        <div className="martis-auth-foot">
          <span>{footerText}</span>
        </div>
      )}
    </div>
  )
}
