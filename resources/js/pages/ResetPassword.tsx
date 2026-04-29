import { useState, useEffect, type FormEvent } from 'react'
import { Link, useNavigate, useParams, useSearchParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { ArrowRightIcon, EyeIcon, EyeSlashIcon } from '@phosphor-icons/react'
import { useAuth } from '@/contexts/AuthContext'
import { useToast } from '@/contexts/ToastContext'
import { api, ApiError } from '@/lib/api'
import { config } from '@/lib/config'
import { AuthFrame } from '@/components/auth/AuthFrame'

/**
 * Reset password — set the new password using the token from the email.
 *
 * Token comes from the URL path; the email comes from the query string
 * (`?email=`) which is what Laravel's default reset-link notification
 * generates. Consumers using a custom notification need to keep the
 * same shape or override `Martis\Contracts\ResetsUserPasswords` to
 * accept their custom payload.
 *
 * POSTs to `/api/auth/password/reset` and on success sends the user to
 * `/login` with a success toast.
 */
export function ResetPasswordPage() {
  const { user, isLoading } = useAuth()
  const { token } = useParams<{ token: string }>()
  const [searchParams] = useSearchParams()
  const navigate = useNavigate()
  const { addToast } = useToast()
  const { t } = useTranslation('auth')

  const [email, setEmail] = useState(searchParams.get('email') ?? '')
  const [password, setPassword] = useState('')
  const [passwordConfirmation, setPasswordConfirmation] = useState('')
  const [showPassword, setShowPassword] = useState(false)
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [submitting, setSubmitting] = useState(false)

  const passwordReset = config.auth?.passwordReset
  const enabled = passwordReset?.enabled === true && !passwordReset?.url

  useEffect(() => {
    if (!isLoading && user) navigate('/', { replace: true })
  }, [isLoading, user, navigate])

  useEffect(() => {
    if (!enabled) navigate('/login', { replace: true })
  }, [enabled, navigate])

  if (!enabled) return null
  if (!isLoading && user) return null

  async function handleSubmit(e: FormEvent) {
    e.preventDefault()
    setErrors({})
    if (password !== passwordConfirmation) {
      setErrors({
        password_confirmation: t('reset_password_mismatch', {
          defaultValue: 'Passwords do not match.',
        }),
      })
      return
    }
    setSubmitting(true)
    try {
      await api.post('/api/auth/password/reset', {
        token,
        email,
        password,
        password_confirmation: passwordConfirmation,
      })
      addToast(
        'success',
        t('reset_password_success', {
          defaultValue: 'Password updated. Sign in with your new credentials.',
        }),
      )
      navigate('/login', { replace: true })
    } catch (err) {
      if (err instanceof ApiError) {
        if (err.status === 422 && err.errors) {
          setErrors(err.errorsByField())
        } else if (err.status === 404 || err.status === 501) {
          addToast(
            'error',
            t('reset_password_endpoint_missing', {
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
        {t('reset_password_title', { defaultValue: 'Set a new password' })}
      </h2>
      <p className="martis-auth-sub">
        {t('reset_password_sub', { defaultValue: 'Choose a new password for your account.' })}
      </p>

      <form onSubmit={(e) => void handleSubmit(e)} noValidate style={{ marginTop: 24 }}>
        <input type="hidden" name="token" value={token ?? ''} />

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
            disabled={submitting}
            required
            readOnly={!!searchParams.get('email')}
          />
          {errors.email && <div className="martis-field-error">{errors.email}</div>}
        </div>

        <div style={{ marginBottom: 12 }}>
          <label htmlFor="password" className="martis-label">
            {t('reset_password_new', { defaultValue: 'New password' })}
          </label>
          <div style={{ position: 'relative' }}>
            <input
              id="password"
              name="password"
              type={showPassword ? 'text' : 'password'}
              autoComplete="new-password"
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              className="martis-input"
              disabled={submitting}
              required
              autoFocus
            />
            <button
              type="button"
              onClick={() => setShowPassword((v) => !v)}
              className="martis-input-toggle"
              tabIndex={-1}
              aria-label={showPassword ? 'Hide password' : 'Show password'}
            >
              {showPassword ? <EyeSlashIcon size={14} /> : <EyeIcon size={14} />}
            </button>
          </div>
          {errors.password && <div className="martis-field-error">{errors.password}</div>}
        </div>

        <div style={{ marginBottom: 12 }}>
          <label htmlFor="password_confirmation" className="martis-label">
            {t('reset_password_confirm', { defaultValue: 'Confirm password' })}
          </label>
          <input
            id="password_confirmation"
            name="password_confirmation"
            type={showPassword ? 'text' : 'password'}
            autoComplete="new-password"
            value={passwordConfirmation}
            onChange={(e) => setPasswordConfirmation(e.target.value)}
            className="martis-input"
            disabled={submitting}
            required
          />
          {errors.password_confirmation && (
            <div className="martis-field-error">{errors.password_confirmation}</div>
          )}
        </div>

        <button
          type="submit"
          className="martis-btn-primary"
          style={{ width: '100%', height: 40, marginTop: 16 }}
          disabled={submitting}
        >
          {submitting
            ? t('reset_password_submitting', { defaultValue: 'Updating…' })
            : t('reset_password_submit', { defaultValue: 'Update password' })}
          {!submitting && <ArrowRightIcon size={14} />}
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
