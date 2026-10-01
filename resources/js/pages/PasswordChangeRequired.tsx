import { useState, type FormEvent } from 'react'
import { useTranslation } from 'react-i18next'
import { ArrowRightIcon } from '@phosphor-icons/react'
import { useAuth } from '@/contexts/AuthContext'
import { useToast } from '@/contexts/ToastContext'
import { api, ApiError } from '@/lib/api'
import { BASE_PATH } from '@/lib/config'
import { AuthFrame } from '@/components/auth/AuthFrame'
import { FieldError } from '@/components/auth/FieldError'
import { PasswordFieldInput } from '@/components/fields/PasswordField'
import { PasswordConfirmationFieldInput } from '@/components/fields/PasswordConfirmationField'
import { confirmationField, policyPasswordField } from '@/lib/passwordPolicy'

/**
 * The forced password change page (v2.3.0), at `/password/change`.
 *
 * `EnsurePasswordIsChanged` holds a user the app flags
 * (`martis.auth.password_change`) until they choose a new password; the
 * login, the 2FA challenge, the session bootstrap and every 409 send them
 * here. The new password follows the app's password policy, whose checklist
 * the field shows.
 *
 * Override via:
 *   php artisan martis:component --type=password-change-page
 */
export function PasswordChangeRequiredPage() {
  const { logout } = useAuth()
  const { addToast } = useToast()
  const { t } = useTranslation('auth')

  const [current, setCurrent] = useState('')
  const [password, setPassword] = useState('')
  const [passwordConfirmation, setPasswordConfirmation] = useState('')
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [submitting, setSubmitting] = useState(false)

  const passwordField = policyPasswordField('password', t('password_change_new', { defaultValue: 'New password' }))
  const confirmField = confirmationField('password_confirmation', 'password', t('password_change_confirm', { defaultValue: 'Confirm new password' }))

  async function handleSubmit(e: FormEvent) {
    e.preventDefault()
    setErrors({})
    if (password !== passwordConfirmation) {
      setErrors({ password_confirmation: t('password_change_mismatch', { defaultValue: 'Passwords do not match.' }) })
      return
    }
    setSubmitting(true)
    try {
      const res = await api.post<{ message?: string }>('/api/auth/password/change', {
        current_password: current,
        password,
        password_confirmation: passwordConfirmation,
      })
      addToast('success', res?.message ?? t('password_change_done', { defaultValue: 'Your password was changed.' }))
      // A full load: the shell, its queries and the session bootstrap start
      // over without the gate.
      window.location.href = BASE_PATH + '/'
    } catch (err) {
      if (err instanceof ApiError && err.status === 422 && err.errors) {
        setErrors(err.errorsByField())
      } else {
        addToast('error', err instanceof Error && err.message !== '' ? err.message : t('error'))
      }
      setSubmitting(false)
    }
  }

  return (
    <AuthFrame>
      <h2 className="martis-auth-title">
        {t('password_change_title', { defaultValue: 'Choose a new password' })}
      </h2>
      <p className="martis-auth-sub">
        {t('password_change_sub', { defaultValue: 'Your account needs a new password before you continue.' })}
      </p>

      <form onSubmit={(e) => void handleSubmit(e)} noValidate style={{ marginTop: 24 }}>
        <div style={{ marginBottom: 12 }}>
          <label htmlFor="current_password" className="martis-label">
            {t('password_change_current', { defaultValue: 'Current password' })}
          </label>
          <input
            id="current_password"
            name="current_password"
            type="password"
            autoComplete="current-password"
            value={current}
            onChange={(e) => setCurrent(e.target.value)}
            className="martis-input"
            disabled={submitting}
            required
            autoFocus
          />
          <FieldError message={errors.current_password} />
        </div>

        <div style={{ marginBottom: 12 }}>
          <label htmlFor="password" className="martis-label">
            {t('password_change_new', { defaultValue: 'New password' })}
          </label>
          <PasswordFieldInput
            field={passwordField}
            value={password}
            onChange={(v) => setPassword(v === null || v === undefined ? '' : String(v))}
            error={errors.password}
            formValues={{ password }}
            disabled={submitting}
            announceError
          />
        </div>

        <div style={{ marginBottom: 12 }}>
          <label htmlFor="password_confirmation" className="martis-label">
            {t('password_change_confirm', { defaultValue: 'Confirm new password' })}
          </label>
          <PasswordConfirmationFieldInput
            field={confirmField}
            value={passwordConfirmation}
            onChange={(v) => setPasswordConfirmation(v === null || v === undefined ? '' : String(v))}
            error={errors.password_confirmation}
            formValues={{ password }}
            disabled={submitting}
            announceError
          />
        </div>

        <button
          type="submit"
          className="martis-btn-primary"
          style={{ width: '100%', height: 40, marginTop: 16 }}
          disabled={submitting}
        >
          {submitting
            ? t('password_change_submitting', { defaultValue: 'Saving…' })
            : t('password_change_submit', { defaultValue: 'Save new password' })}
          {!submitting && <ArrowRightIcon size={14} />}
        </button>
      </form>

      <button
        type="button"
        onClick={() => void logout()}
        className="martis-btn-secondary"
        style={{ width: '100%', height: 40, marginTop: 8, justifyContent: 'center' }}
      >
        {t('password_change_sign_out', { defaultValue: 'Sign out' })}
      </button>
    </AuthFrame>
  )
}
