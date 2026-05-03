import { useEffect, useState } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
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
  const [searchParams] = useSearchParams()

  const [resending, setResending] = useState(false)

  // The verify-link endpoint redirects logged-out users to
  // /login?verified=1 — but if a consumer routes them here instead,
  // surface the success toast in this component too. Idempotent: the
  // toast fires once on mount when the flag is present.
  useEffect(() => {
    if (searchParams.get('verified') === '1') {
      addToast(
        'success',
        t('verify_success', {
          defaultValue: 'Email verified. You can sign in now.',
        }),
      )
    }
  }, [searchParams, addToast, t])

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

  // Two render branches:
  //   - Authenticated user (came in via login → SPA bounce): show
  //     personalized "We sent it to {email}", offer Resend + Sign-out.
  //   - Guest (came in via the post-register redirect, before any
  //     login): no email available, no auth-only resend endpoint either,
  //     so guide them to login as the next step.
  const isGuest = user === null

  return (
    <AuthFrame>
      <h2 className="martis-auth-title">
        {t('verify_title', { defaultValue: 'Verify your email' })}
      </h2>
      <p className="martis-auth-sub">
        {isGuest
          ? t('verify_sub_guest', {
              defaultValue: 'Check the inbox you registered with for the verification link, then sign in.',
            })
          : t('verify_sub', {
              defaultValue: 'We sent a verification link to {{email}}. Click it to continue.',
              email: user?.email ?? '',
            })}
      </p>

      {isGuest ? (
        <button
          type="button"
          onClick={() => navigate('/login', { replace: true })}
          className="martis-btn-primary"
          style={{ width: '100%', height: 40, marginTop: 24 }}
        >
          {t('verify_back_to_login', { defaultValue: 'Back to sign in' })}
        </button>
      ) : (
        <>
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
        </>
      )}
    </AuthFrame>
  )
}
