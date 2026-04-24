import { useState, type FormEvent, type KeyboardEvent } from 'react'
import { Link, Navigate, useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { ArrowRightIcon, EyeIcon, EyeSlashIcon } from '@phosphor-icons/react'
import { useAuth } from '@/contexts/AuthContext'
import { useToast } from '@/contexts/ToastContext'
import { api, ApiError } from '@/lib/api'
import { config } from '@/lib/config'
import { AuthFrame } from '@/components/auth/AuthFrame'

/**
 * Self-service registration page.
 *
 * Gated by `config.auth.registration.enabled`. When the flag is `false`
 * the route redirects to /login so external links are harmless.
 *
 * Martis does not ship a built-in register controller — consumers are
 * expected to expose their own POST endpoint. The default submit target
 * is `/api/auth/register` inside the Martis prefix; override by setting
 * `config.auth.registration.url` to the full URL of the external form
 * (the Login link uses the same pointer to redirect off-platform).
 *
 * The form validates client-side (matching passwords) before posting
 * and surfaces 422 errors per-field so host-side validation rules line
 * up with what the user sees.
 */
export function RegisterPage() {
  const { user, isLoading } = useAuth()
  const navigate = useNavigate()
  const { addToast } = useToast()
  const { t } = useTranslation('auth')

  const [name, setName] = useState('')
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [passwordConfirmation, setPasswordConfirmation] = useState('')
  const [showPassword, setShowPassword] = useState(false)
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [submitting, setSubmitting] = useState(false)

  const registration = config.auth?.registration
  const enabled = registration?.enabled === true

  if (!enabled) return <Navigate to="/login" replace />
  if (!isLoading && user) return <Navigate to="/" replace />

  async function handleSubmit(e: FormEvent) {
    e.preventDefault()
    setErrors({})
    if (password !== passwordConfirmation) {
      setErrors({ password_confirmation: t('register_password_mismatch', { defaultValue: 'Passwords do not match.' }) })
      return
    }
    setSubmitting(true)
    try {
      await api.post('/api/auth/register', {
        name,
        email,
        password,
        password_confirmation: passwordConfirmation,
      })
      addToast('success', t('register_success', { defaultValue: 'Account created. Please sign in.' }))
      navigate('/login', { replace: true })
    } catch (err) {
      if (err instanceof ApiError) {
        if (err.status === 422 && err.errors) {
          setErrors(err.errorsByField())
        } else if (err.status === 404 || err.status === 501) {
          addToast('error', t('register_endpoint_missing', {
            defaultValue: 'Registration endpoint is not available. Ask a workspace admin to finish setting it up.',
          }))
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

  function handleFieldKeyDown(e: KeyboardEvent<HTMLInputElement>) {
    if (e.key !== 'Enter' || submitting) return
    e.preventDefault()
    e.currentTarget.form?.requestSubmit()
  }

  return (
    <AuthFrame>
      <h2 className="martis-auth-title">{t('register_title', { defaultValue: 'Create your account' })}</h2>
      <p className="martis-auth-sub">
        {t('register_sub', { defaultValue: 'Get started with your workspace. We just need a few details.' })}
      </p>

      <form onSubmit={(e) => void handleSubmit(e)} noValidate style={{ marginTop: 20 }}>
        <div style={{ marginBottom: 12 }}>
          <label
            htmlFor="register-name"
            style={{ display: 'block', fontSize: 13, color: 'var(--martis-text-muted)', marginBottom: 6 }}
          >
            {t('register_name', { defaultValue: 'Name' })}
          </label>
          <input
            id="register-name"
            type="text"
            name="name"
            autoComplete="name"
            value={name}
            onChange={(e) => setName(e.target.value)}
            onKeyDown={handleFieldKeyDown}
            placeholder={t('register_name_placeholder', { defaultValue: 'Your full name' })}
            required
            className="w-full rounded-md py-2 px-3 text-sm focus:outline-none focus:ring-1"
            style={{
              backgroundColor: 'var(--martis-input-bg)',
              border: `1px solid ${errors.name ? 'var(--martis-danger)' : 'var(--martis-border)'}`,
              color: 'var(--martis-text)',
            }}
          />
          {errors.name && (
            <p style={{ marginTop: 6, fontSize: 12, color: 'var(--martis-danger)' }}>{errors.name}</p>
          )}
        </div>

        <div style={{ marginBottom: 12 }}>
          <label
            htmlFor="register-email"
            style={{ display: 'block', fontSize: 13, color: 'var(--martis-text-muted)', marginBottom: 6 }}
          >
            {t('email')}
          </label>
          <input
            id="register-email"
            type="email"
            name="email"
            autoComplete="email"
            value={email}
            onChange={(e) => setEmail(e.target.value)}
            onKeyDown={handleFieldKeyDown}
            placeholder={t('email_placeholder')}
            required
            className="w-full rounded-md py-2 px-3 text-sm focus:outline-none focus:ring-1"
            style={{
              backgroundColor: 'var(--martis-input-bg)',
              border: `1px solid ${errors.email ? 'var(--martis-danger)' : 'var(--martis-border)'}`,
              color: 'var(--martis-text)',
            }}
          />
          {errors.email && (
            <p style={{ marginTop: 6, fontSize: 12, color: 'var(--martis-danger)' }}>{errors.email}</p>
          )}
        </div>

        <div style={{ marginBottom: 12 }}>
          <label
            htmlFor="register-password"
            style={{ display: 'block', fontSize: 13, color: 'var(--martis-text-muted)', marginBottom: 6 }}
          >
            {t('password')}
          </label>
          <div style={{ position: 'relative' }}>
            <input
              id="register-password"
              type={showPassword ? 'text' : 'password'}
              name="password"
              autoComplete="new-password"
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              onKeyDown={handleFieldKeyDown}
              placeholder={t('password_placeholder')}
              required
              className="w-full rounded-md py-2 pl-3 pr-10 text-sm focus:outline-none focus:ring-1"
              style={{
                backgroundColor: 'var(--martis-input-bg)',
                border: `1px solid ${errors.password ? 'var(--martis-danger)' : 'var(--martis-border)'}`,
                color: 'var(--martis-text)',
              }}
            />
            <button
              type="button"
              onClick={() => setShowPassword((s) => !s)}
              tabIndex={-1}
              aria-label={showPassword ? t('hide_password') : t('show_password')}
              style={{
                position: 'absolute',
                right: 8,
                top: '50%',
                transform: 'translateY(-50%)',
                background: 'none',
                border: 0,
                cursor: 'pointer',
                color: 'var(--martis-text-muted)',
                display: 'inline-flex',
                alignItems: 'center',
                padding: 4,
              }}
            >
              {showPassword ? <EyeSlashIcon size={16} /> : <EyeIcon size={16} />}
            </button>
          </div>
          {errors.password && (
            <p style={{ marginTop: 6, fontSize: 12, color: 'var(--martis-danger)' }}>{errors.password}</p>
          )}
        </div>

        <div style={{ marginBottom: 20 }}>
          <label
            htmlFor="register-password-confirm"
            style={{ display: 'block', fontSize: 13, color: 'var(--martis-text-muted)', marginBottom: 6 }}
          >
            {t('register_password_confirmation', { defaultValue: 'Confirm password' })}
          </label>
          <input
            id="register-password-confirm"
            type={showPassword ? 'text' : 'password'}
            name="password_confirmation"
            autoComplete="new-password"
            value={passwordConfirmation}
            onChange={(e) => setPasswordConfirmation(e.target.value)}
            onKeyDown={handleFieldKeyDown}
            placeholder={t('password_placeholder')}
            required
            className="w-full rounded-md py-2 px-3 text-sm focus:outline-none focus:ring-1"
            style={{
              backgroundColor: 'var(--martis-input-bg)',
              border: `1px solid ${errors.password_confirmation ? 'var(--martis-danger)' : 'var(--martis-border)'}`,
              color: 'var(--martis-text)',
            }}
          />
          {errors.password_confirmation && (
            <p style={{ marginTop: 6, fontSize: 12, color: 'var(--martis-danger)' }}>
              {errors.password_confirmation}
            </p>
          )}
        </div>

        <button
          type="submit"
          disabled={submitting}
          className="martis-btn-primary"
          style={{ width: '100%', justifyContent: 'center', height: 40 }}
        >
          {submitting
            ? t('register_submitting', { defaultValue: 'Creating account…' })
            : t('register_submit', { defaultValue: 'Create account' })}
          {!submitting && <ArrowRightIcon size={14} weight="bold" />}
        </button>

        <div style={{ textAlign: 'center', marginTop: 18, fontSize: 13, color: 'var(--martis-text-muted)' }}>
          {t('register_have_account', { defaultValue: 'Already have an account?' })}{' '}
          <Link to="/login" style={{ color: 'var(--martis-accent)', textDecoration: 'none' }}>
            {t('sign_in')}
          </Link>
        </div>
      </form>
    </AuthFrame>
  )
}
