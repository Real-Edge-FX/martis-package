import { useState, type FormEvent, type KeyboardEvent } from 'react'
import { Link, Navigate, useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { ArrowRightIcon } from '@phosphor-icons/react'
import { useAuth } from '@/contexts/AuthContext'
import { useToast } from '@/contexts/ToastContext'
import { api, ApiError } from '@/lib/api'
import { config } from '@/lib/config'
import { useAuthCopy } from '@/lib/authCopy'
import { AuthFrame } from '@/components/auth/AuthFrame'
import { FieldError } from '@/components/auth/FieldError'
import { PasswordFieldInput } from '@/components/fields/PasswordField'
import { PasswordConfirmationFieldInput } from '@/components/fields/PasswordConfirmationField'
import type { FieldDefinition } from '@/types'

/** v1.8.2 — share the FieldDefinition shape with PasswordSection so
 *  Register's password block has the same strength meter, live
 *  checklist, and confirmation-match indicator as the rest of the
 *  product. */
function buildRegisterPasswordField(): FieldDefinition {
  return {
    attribute: 'password',
    label: '',
    type: 'password',
    nullable: false,
    readonly: false,
    required: true,
    sortable: false,
    searchable: false,
    showOnIndex: false,
    showOnDetail: false,
    showOnForms: true,
    strengthMeter: true,
    showRequirements: true,
    requirements: {
      minLength: 8,
      uppercase: true,
      lowercase: true,
      number: true,
      symbol: true,
      noCommon: true,
    },
  } as unknown as FieldDefinition
}

function buildRegisterConfirmField(): FieldDefinition {
  return {
    attribute: 'password_confirmation',
    label: '',
    type: 'password_confirmation',
    nullable: true,
    readonly: false,
    required: true,
    sortable: false,
    searchable: false,
    showOnIndex: false,
    showOnDetail: false,
    showOnForms: true,
    confirms: 'password',
  } as unknown as FieldDefinition
}

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
  const tCopy = useAuthCopy()

  const [name, setName] = useState('')
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [passwordConfirmation, setPasswordConfirmation] = useState('')
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [submitting, setSubmitting] = useState(false)

  // Reuse the same field stack as the Profile password section —
  // strength meter, live requirements checklist, match indicator. v1.8.2.
  const passwordField = buildRegisterPasswordField()
  const confirmField = buildRegisterConfirmField()

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
      // When the workspace requires email verification, the user is NOT
      // ready to sign in yet — sending them to /login with a "Please
      // sign in" toast is misleading because the next login attempt
      // will be gated by the verification flag and they'll be bounced
      // to /email/verify regardless. Surface a "check your inbox"
      // message that matches the actual next step.
      const verifyRequired = config.auth?.emailVerification?.enabled === true
      if (verifyRequired) {
        addToast(
          'success',
          t('register_success_verify', {
            defaultValue: 'Account created. Check your inbox to verify your email before signing in.',
          }),
        )
        navigate('/email/verify', { replace: true })
      } else {
        addToast('success', t('register_success', { defaultValue: 'Account created. Please sign in.' }))
        navigate('/login', { replace: true })
      }
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
      <h2 className="martis-auth-title">
        {tCopy('register', 'title', 'register_title', 'Create your account')}
      </h2>
      <p className="martis-auth-sub">
        {tCopy('register', 'subtitle', 'register_sub', 'Get started with your workspace. We just need a few details.')}
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
          <FieldError message={errors.name} />
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
            autoComplete="username email"
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
          <FieldError message={errors.email} />
        </div>

        <div style={{ marginBottom: 12 }}>
          <label
            htmlFor="password"
            style={{ display: 'block', fontSize: 13, color: 'var(--martis-text-muted)', marginBottom: 6 }}
          >
            {t('password')}
          </label>
          <PasswordFieldInput
            field={passwordField}
            value={password}
            onChange={(v) => setPassword(v === null || v === undefined ? '' : String(v))}
            error={errors.password}
            formValues={{ password }}
          />
        </div>

        <div style={{ marginBottom: 20 }}>
          <label
            htmlFor="password_confirmation"
            style={{ display: 'block', fontSize: 13, color: 'var(--martis-text-muted)', marginBottom: 6 }}
          >
            {t('register_password_confirmation', { defaultValue: 'Confirm password' })}
          </label>
          <PasswordConfirmationFieldInput
            field={confirmField}
            value={passwordConfirmation}
            onChange={(v) => setPasswordConfirmation(v === null || v === undefined ? '' : String(v))}
            error={errors.password_confirmation}
            formValues={{ password }}
          />
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
