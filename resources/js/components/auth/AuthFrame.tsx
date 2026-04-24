import { type ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import { config } from '@/lib/config'
import { AuthControls } from '@/components/auth/AuthControls'
import logoSrc from '@images/logo.png'

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
 * Renders the dot-grid background with the accent halo, a brand row at
 * the top of the card (logo only — the Martis wordmark lives inside the
 * logo asset), a slot for page-specific content, and the Shell-style
 * centered footer ("© {brand} · Powered by Martis"). Guest-mode theme
 * and language controls sit in the top-right, so visitors can switch
 * before signing in.
 */
export function AuthFrame({ children, width }: AuthFrameProps) {
  const { t } = useTranslation('navigation')
  const brand = config.brand ?? 'Martis'
  const footerText = config.footer?.enabled === false
    ? null
    : (config.footer?.text ?? t('footer_default', { brand, defaultValue: '\u00a9 {{brand}} \u00b7 Powered by Martis' }))
  const cardStyle = width !== undefined ? { maxWidth: typeof width === 'number' ? `${width}px` : width } : undefined

  return (
    <div className="martis-auth-frame">
      <div className="martis-auth-bg" aria-hidden="true" />
      <AuthControls />
      <div className="martis-auth-card" style={cardStyle}>
        <div className="martis-auth-brand">
          <img src={logoSrc} alt={brand} />
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
