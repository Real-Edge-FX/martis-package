import { useState, type FormEvent } from 'react'
import { useTranslation } from 'react-i18next'
import { InputText } from 'primereact/inputtext'
import { IconField } from 'primereact/iconfield'
import { InputIcon } from 'primereact/inputicon'
import { LockIcon } from '@phosphor-icons/react'
import { api, ApiError } from '@/lib/api'
import { useToast } from '@/contexts/ToastContext'
import { useAuth } from '@/contexts/AuthContext'
import { PasswordFieldInput } from '@/components/fields/PasswordField'
import { PasswordConfirmationFieldInput } from '@/components/fields/PasswordConfirmationField'
import { confirmationField, policyPasswordField } from '@/lib/passwordPolicy'

// Profile uses the same Password + PasswordConfirmation field stack as any
// resource form (strength meter, live match indicator, shared clear), with
// the checklist of the app's password policy. The server validates with
// `Password::defaults()` and has the last word (v2.3.0).

export function PasswordSection() {
  const { t } = useTranslation('profile')
  const { addToast } = useToast()
  const { user } = useAuth()
  const [current, setCurrent] = useState('')
  const [next, setNext] = useState('')
  const [confirm, setConfirm] = useState('')
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [saving, setSaving] = useState(false)

  const nextField = policyPasswordField('password', t('new_password'))
  const confirmField = confirmationField('password_confirmation', 'password', t('confirm_password'))

  async function handleSubmit(e: FormEvent) {
    e.preventDefault()

    if (next !== confirm) {
      addToast('error', t('password_mismatch'))
      return
    }

    setErrors({})
    setSaving(true)
    try {
      await api.post('/api/profile/password', {
        current_password: current,
        password: next,
        password_confirmation: confirm,
      })
      addToast('success', t('password_updated'))
      setCurrent('')
      setNext('')
      setConfirm('')
    } catch (err) {
      if (err instanceof ApiError) {
        addToast('error', err.message || t('error'))
        if (err.errors) setErrors(err.errorsByField())
      } else {
        addToast('error', t('error'))
      }
    } finally {
      setSaving(false)
    }
  }

  return (
    <section
      className="rounded-xl p-6 border border-solid martis-border martis-card-bg"
      aria-labelledby="password-section-title"
    >
      <h2 id="password-section-title" className="text-lg font-semibold martis-text mb-4">
        {t('password')}
      </h2>
      <form onSubmit={(e) => void handleSubmit(e)} noValidate className="space-y-4 max-w-lg">
        {/* Hidden username field for password manager / accessibility.
            Chrome / Firefox warn in the console if a password form has
            no associated username field. We provide the email as a
            readonly text input that screen readers and password managers
            can pick up. v1.8.0. */}
        <input
          type="text"
          name="username"
          autoComplete="username"
          defaultValue={user?.email ?? ''}
          readOnly
          aria-hidden="true"
          tabIndex={-1}
          style={{ position: 'absolute', left: '-9999px', width: 1, height: 1, opacity: 0 }}
        />
        <div className="flex flex-col gap-2">
          <label htmlFor="current-password" className="text-sm font-medium martis-text-muted">
            {t('current_password')}
          </label>
          <IconField iconPosition="left">
            <InputIcon><LockIcon size={14} /></InputIcon>
            <InputText
              id="current-password"
              type="password"
              value={current}
              onChange={(e) => setCurrent(e.target.value)}
              invalid={!!errors.current_password}
              className="w-full"
              autoComplete="current-password"
              required
            />
          </IconField>
          {errors.current_password && <small className="p-error">{errors.current_password}</small>}
        </div>

        <div className="flex flex-col gap-2">
          <label htmlFor="password" className="text-sm font-medium martis-text-muted">
            {t('new_password')}
          </label>
          <PasswordFieldInput
            field={nextField}
            value={next}
            onChange={(v) => setNext(v === null || v === undefined ? '' : String(v))}
            error={errors.password}
            formValues={{ password: next }}
          />
        </div>

        <div className="flex flex-col gap-2">
          <label htmlFor="password_confirmation" className="text-sm font-medium martis-text-muted">
            {t('confirm_password')}
          </label>
          <PasswordConfirmationFieldInput
            field={confirmField}
            value={confirm}
            onChange={(v) => setConfirm(v === null || v === undefined ? '' : String(v))}
            error={errors.password_confirmation}
            formValues={{ password: next }}
          />
        </div>

        <button
          type="submit"
          disabled={saving}
          className="inline-flex items-center gap-2 rounded-lg px-4 py-2 text-sm font-medium text-martis-accent-contrast transition-colors hover:opacity-90 disabled:opacity-50"
          style={{ backgroundColor: 'var(--martis-accent)' }}
        >
          {saving ? t('updating_password') : t('update_password')}
        </button>
      </form>
    </section>
  )
}
