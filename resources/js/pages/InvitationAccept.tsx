import { useState, type FormEvent } from 'react'
import { Link, useParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { ArrowRightIcon, EyeIcon, EyeSlashIcon } from '@phosphor-icons/react'
import { useToast } from '@/contexts/ToastContext'
import { api, ApiError } from '@/lib/api'
import { AuthFrame } from '@/components/auth/AuthFrame'
import { FieldError } from '@/components/auth/FieldError'

/**
 * Invitation accept — the invitee's set-password screen reached from the
 * emailed invite link (`/invitations/accept/:token`).
 *
 * `InvitationController::show()` (Task 8) deliberately serves the SAME
 * SPA shell (200) for a valid AND an invalid/expired/used token — the
 * server never reveals token validity from the page load, to stay
 * enumeration-safe. This screen therefore renders the set-password form
 * optimistically and only learns the token was bad from the POST
 * response: `accept()` returns the exact same 422 envelope
 * (`{message, errors}`) for a bad password (`errors.password` /
 * `errors.name`) and for an invalid token (`errors.token`) — the shape
 * never differs, only the field name does. We key off `errors.token`
 * specifically to flip to the neutral invalid-link screen; every other
 * 422 stays inline on the form so the invitee can fix and retry.
 *
 * Only the default `signup_fields` (`name`, `password`) are rendered —
 * matching `InvitationController::acceptRules()`'s default. `email` and
 * `role` are never collected from the client; the server resolves them
 * from the invitation itself.
 *
 * On success the JSON envelope carries `redirect` (an absolute URL —
 * `route('martis.index')` or the configured
 * `martis.invitations.redirect_after_accept`). We follow it with a full
 * page navigation (`window.location.href`), not the SPA router: the
 * accept call just changed the session (login-after-accept), so a hard
 * navigation is what actually picks up the new auth state — mirrors how
 * `LoginPage` sends SSO/flow redirects through `window.location.href`
 * rather than `navigate()`.
 */
export function InvitationAcceptPage() {
  const { token } = useParams<{ token: string }>()
  const { addToast } = useToast()
  const { t } = useTranslation('auth')

  const [name, setName] = useState('')
  const [password, setPassword] = useState('')
  const [passwordConfirmation, setPasswordConfirmation] = useState('')
  const [showPassword, setShowPassword] = useState(false)
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [submitting, setSubmitting] = useState(false)
  const [invalid, setInvalid] = useState(false)

  async function handleSubmit(e: FormEvent) {
    e.preventDefault()
    setErrors({})
    if (password !== passwordConfirmation) {
      setErrors({
        password_confirmation: t('invitation_accept_mismatch', {
          defaultValue: 'Passwords do not match.',
        }),
      })
      return
    }
    setSubmitting(true)
    try {
      const res = await api.post<{ ok?: boolean; redirect?: string }>('/api/invitations/accept', {
        token,
        name,
        password,
        password_confirmation: passwordConfirmation,
      })
      window.location.href = res?.redirect || '/login'
    } catch (err) {
      if (err instanceof ApiError && err.status === 422 && err.errors) {
        const byField = err.errorsByField()
        if (byField.token) {
          // Neutral rejection — unknown/expired/revoked/used token, or an
          // email already registered. Never distinguish which.
          setInvalid(true)
        } else {
          setErrors(byField)
        }
      } else if (err instanceof ApiError) {
        addToast('error', err.message || t('error'))
      } else {
        addToast('error', err instanceof Error ? err.message : t('error'))
      }
    } finally {
      setSubmitting(false)
    }
  }

  if (invalid) {
    return (
      <AuthFrame>
        <h2 className="martis-auth-title">
          {t('invitation_accept_invalid_title', { defaultValue: 'Invitation link invalid' })}
        </h2>
        <p className="martis-auth-sub">
          {t('invitation_accept_invalid_message', {
            defaultValue: 'This invitation link is invalid or has expired.',
          })}
        </p>
        <div style={{ marginTop: 20, textAlign: 'center' }}>
          <Link to="/login" className="martis-auth-forgot">
            {t('forgot_password_back_to_login', { defaultValue: 'Back to sign in' })}
          </Link>
        </div>
      </AuthFrame>
    )
  }

  return (
    <AuthFrame>
      <h2 className="martis-auth-title">
        {t('invitation_accept_title', { defaultValue: 'Accept your invitation' })}
      </h2>
      <p className="martis-auth-sub">
        {t('invitation_accept_sub', {
          defaultValue: 'Set a password to activate your account.',
        })}
      </p>

      <form onSubmit={(e) => void handleSubmit(e)} noValidate style={{ marginTop: 24 }}>
        <input type="hidden" name="token" value={token ?? ''} />

        <div style={{ marginBottom: 12 }}>
          <label htmlFor="name" className="martis-label">
            {t('invitation_accept_name', { defaultValue: 'Full name' })}
          </label>
          <input
            id="name"
            name="name"
            type="text"
            autoComplete="name"
            value={name}
            onChange={(e) => setName(e.target.value)}
            className="martis-input"
            disabled={submitting}
            required
            autoFocus
          />
          <FieldError message={errors.name} />
        </div>

        <div style={{ marginBottom: 12 }}>
          <label htmlFor="password" className="martis-label">
            {t('password', { defaultValue: 'Password' })}
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
            />
            <button
              type="button"
              onClick={() => setShowPassword((v) => !v)}
              className="martis-input-toggle"
              tabIndex={-1}
              aria-label={showPassword
                ? t('invitation_accept_hide', { defaultValue: 'Hide password' })
                : t('invitation_accept_show', { defaultValue: 'Show password' })}
            >
              {showPassword ? <EyeSlashIcon size={14} /> : <EyeIcon size={14} />}
            </button>
          </div>
          <FieldError message={errors.password} />
        </div>

        <div style={{ marginBottom: 12 }}>
          <label htmlFor="password_confirmation" className="martis-label">
            {t('invitation_accept_confirm', { defaultValue: 'Confirm password' })}
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
          <FieldError message={errors.password_confirmation} />
        </div>

        <button
          type="submit"
          className="martis-btn-primary"
          style={{ width: '100%', height: 40, marginTop: 16 }}
          disabled={submitting}
        >
          {submitting
            ? t('invitation_accept_submitting', { defaultValue: 'Activating…' })
            : t('invitation_accept_submit', { defaultValue: 'Accept invitation' })}
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
