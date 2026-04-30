import { useState, useEffect, type FormEvent } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { ArrowRightIcon } from '@phosphor-icons/react'
import { useAuth } from '@/contexts/AuthContext'
import { useToast } from '@/contexts/ToastContext'
import { api, ApiError } from '@/lib/api'
import { config } from '@/lib/config'
import { useAuthCopy } from '@/lib/authCopy'
import { AuthFrame } from '@/components/auth/AuthFrame'
import { FieldError } from '@/components/auth/FieldError'

/**
 * Forgot password — request a reset link.
 *
 * Gated by `config.auth.passwordReset.enabled`. When the flag is `false`
 * or the consumer points `auth.passwordReset.url` off-platform, this
 * page is unreachable internally (the route is not registered).
 *
 * POSTs to `/api/auth/password/email`. The shipped backend uses
 * Laravel's password broker; consumers can override the
 * `Martis\Contracts\SendsPasswordResetLinks` binding to take full
 * control of the delivery (queue, branded notification, magic-link).
 */
export function ForgotPasswordPage() {
  const { user, isLoading } = useAuth()
  const navigate = useNavigate()
  const { addToast } = useToast()
  const { t } = useTranslation('auth')
  const tCopy = useAuthCopy()

  const [email, setEmail] = useState('')
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [submitting, setSubmitting] = useState(false)
  const [sent, setSent] = useState(false)

  const passwordReset = config.auth?.passwordReset
  const enabled = passwordReset?.enabled === true && !passwordReset?.url

  // Redirect already-authed users out of the page (post-login no-flash).
  useEffect(() => {
    if (!isLoading && user) navigate('/', { replace: true })
  }, [isLoading, user, navigate])

  // v1.8.0 — bounce back to /login when the feature is off (or the
  // consumer points the URL off-platform). Hooks always run; the
  // declarative effect keeps React's rules-of-hooks honest.
  useEffect(() => {
    if (!enabled) {
      addToast(
        'info',
        t('forgot_password_disabled', {
          defaultValue: 'Password reset is not enabled on this workspace.',
        }),
      )
      navigate('/login', { replace: true })
    }
  }, [enabled, navigate, addToast, t])

  if (!enabled) return null
  if (!isLoading && user) return null

  async function handleSubmit(e: FormEvent) {
    e.preventDefault()
    setErrors({})
    setSubmitting(true)
    try {
      await api.post('/api/auth/password/email', { email })
      setSent(true)
      addToast(
        'success',
        t('forgot_password_sent', {
          defaultValue: 'If an account exists for that email, a reset link is on its way.',
        }),
      )
    } catch (err) {
      if (err instanceof ApiError) {
        if (err.status === 422 && err.errors) {
          setErrors(err.errorsByField())
        } else if (err.status === 404 || err.status === 501) {
          addToast(
            'error',
            t('forgot_password_endpoint_missing', {
              defaultValue: 'Password reset is not enabled on this workspace.',
            }),
          )
        } else {
          addToast('error', err.message || t('error'))
        }
      } else {
        addToast('error', err instanceof Error ? err.message : t('error'))
      }
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <AuthFrame>
      <h2 className="martis-auth-title">
        {tCopy('forgot_password', 'title', 'forgot_password_title', 'Reset your password')}
      </h2>
      <p className="martis-auth-sub">
        {tCopy(
          'forgot_password',
          'subtitle',
          'forgot_password_sub',
          "Enter your email and we'll send you a link to set a new password.",
        )}
      </p>

      <form onSubmit={(e) => void handleSubmit(e)} noValidate style={{ marginTop: 24 }}>
        <div style={{ marginBottom: 12 }}>
          <label htmlFor="email" className="martis-label">
            {t('email', { defaultValue: 'Email' })}
          </label>
          <input
            id="email"
            name="email"
            type="email"
            autoComplete="email"
            value={email}
            onChange={(e) => setEmail(e.target.value)}
            className="martis-input"
            disabled={submitting || sent}
            required
            autoFocus
          />
          <FieldError message={errors.email} />
        </div>

        <button
          type="submit"
          className="martis-btn-primary"
          style={{ width: '100%', height: 40, marginTop: 16 }}
          disabled={submitting || sent}
        >
          {submitting
            ? t('forgot_password_submitting', { defaultValue: 'Sending…' })
            : sent
              ? t('forgot_password_sent_button', { defaultValue: 'Link sent' })
              : t('forgot_password_submit', { defaultValue: 'Send reset link' })}
          {!submitting && !sent && <ArrowRightIcon size={14} />}
        </button>
      </form>

      <div style={{ marginTop: 20, textAlign: 'center' }}>
        <Link to="/login" className="martis-auth-forgot">
          {t('forgot_password_back_to_login', { defaultValue: 'Back to sign in' })}
        </Link>
      </div>
    </AuthFrame>
  )
}
