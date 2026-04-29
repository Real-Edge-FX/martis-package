import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { useAuth } from '@/contexts/AuthContext'
import { useToast } from '@/contexts/ToastContext'
import { api, ApiError } from '@/lib/api'
import { AuthFrame } from '@/components/auth/AuthFrame'

/**
 * Default "verify your email" notice page.
 *
 * Rendered at `/email/verify` when the consumer enables
 * `martis.auth.email_verification.enabled=true` and the logged-in user
 * has not yet confirmed their email address. The Martis-shipped
 * `EnsureEmailIsVerified` middleware redirects there from any
 * protected route.
 *
 * Override via:
 *   php artisan martis:component MyVerifyNotice --type=email-verify-notice-page
 */
export function EmailVerifyNoticePage() {
  const { user, logout } = useAuth()
  const navigate = useNavigate()
  const { addToast } = useToast()
  const { t } = useTranslation('auth')

  const [resending, setResending] = useState(false)

  async function handleResend() {
    setResending(true)
    try {
      await api.post('/api/auth/email/verification-notification', {})
      addToast(
        'success',
        t('verify_resent', { defaultValue: 'Verification link sent. Check your inbox.' }),
      )
    } catch (err) {
      if (err instanceof ApiError) {
        addToast('error', err.message || t('error'))
      } else {
        addToast('error', err instanceof Error ? err.message : t('error'))
      }
    } finally {
      setResending(false)
    }
  }

  async function handleSignOut() {
    await logout()
    navigate('/login', { replace: true })
  }

  return (
    <AuthFrame>
      <h2 className="martis-auth-title">
        {t('verify_title', { defaultValue: 'Verify your email' })}
      </h2>
      <p className="martis-auth-sub">
        {t('verify_sub', {
          defaultValue: "We sent a verification link to {email}. Click it to continue.",
          email: user?.email ?? '',
        })}
      </p>

      <button
        type="button"
        onClick={() => void handleResend()}
        className="martis-btn-primary"
        style={{ width: '100%', height: 40, marginTop: 24 }}
        disabled={resending}
      >
        {resending
          ? t('verify_resending', { defaultValue: 'Sending…' })
          : t('verify_resend', { defaultValue: 'Resend verification link' })}
      </button>

      <button
        type="button"
        onClick={() => void handleSignOut()}
        className="martis-btn-secondary"
        style={{ width: '100%', height: 40, marginTop: 8, justifyContent: 'center' }}
      >
        {t('verify_sign_out', { defaultValue: 'Sign in with a different account' })}
      </button>
    </AuthFrame>
  )
}
