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

  const customLogo = config.logo ?? null
  const customIcon = config.icon ?? null

  return (
    <div className="martis-auth-frame">
      <div className="martis-auth-bg" aria-hidden="true" />
      <AuthControls />
      <div className="martis-auth-card" style={cardStyle}>
        <div className="martis-auth-brand">
          {customLogo ? (
            <img src={customLogo} alt={brand} />
          ) : customIcon ? (
            <>
              <img
                src={customIcon}
                alt=""
                aria-hidden="true"
                style={{ height: '2rem', width: 'auto' }}
              />
              <span style={{ fontSize: '1.5rem', fontWeight: 600, marginLeft: '0.5rem' }}>
                {brand}
              </span>
            </>
          ) : (
            <img src={bundledLogo} alt={brand} />
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
