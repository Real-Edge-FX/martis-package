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
import type { FieldDefinition } from '@/types'

// Note: the legacy `PasswordChecklist` component is no longer used here —
// the ⭐ Password field stack renders its own live checklist + strength meter
// via `showRequirements()`. Keeping the old component around while other
// callers still reference it.

// Profile uses the same Password + PasswordConfirmation field stack as any
// resource form — strength meter, complexity checklist, live match indicator,
// shared clear (×). The field metadata is built here client-side because the
// profile endpoint does not expose a Martis Resource.

function buildPasswordField(attribute: string, label: string): FieldDefinition {
  return {
    attribute,
    label,
    type: 'password',
    nullable: false,
    readonly: false,
    required: true,
    sortable: false,
    searchable: false,
    showOnIndex: false,
    showOnDetail: false,
    showOnForms: true,
    // ⭐ Same defaults as the server-side Password field with every
    // requirement enabled + checklist on.
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

function buildConfirmField(attribute: string, confirms: string, label: string): FieldDefinition {
  return {
    attribute,
    label,
    type: 'password_confirmation',
    nullable: true,
    readonly: false,
    required: true,
    sortable: false,
    searchable: false,
    showOnIndex: false,
    showOnDetail: false,
    showOnForms: true,
    confirms,
  } as unknown as FieldDefinition
}

export function PasswordSection() {
  const { t } = useTranslation('profile')
  const { addToast } = useToast()
  const { user } = useAuth()
  const [current, setCurrent] = useState('')
  const [next, setNext] = useState('')
  const [confirm, setConfirm] = useState('')
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [saving, setSaving] = useState(false)

  const nextField = buildPasswordField('password', t('new_password'))
  const confirmField = buildConfirmField('password_confirmation', 'password', t('confirm_password'))

  function allRequirementsMet(pwd: string): boolean {
    return (
      pwd.length >= 8 &&
      /[A-Z]/.test(pwd) &&
      /[a-z]/.test(pwd) &&
      /\d/.test(pwd) &&
      /[^A-Za-z0-9]/.test(pwd)
    )
  }

  async function handleSubmit(e: FormEvent) {
    e.preventDefault()

    // The field components already surface live feedback (checklist, match
    // indicator). Skip local error state — a single toast is enough; the
    // inline visuals show exactly what's wrong.
    if (!allRequirementsMet(next)) {
      addToast('error', t('password_rules_unmet'))
      return
    }
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
      className="rounded-xl p-6 border martis-border martis-card-bg"
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
            error={errors.new_password}
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
            error={errors.confirm_password}
            formValues={{ password: next }}
          />
        </div>

        <button
          type="submit"
          disabled={saving}
          className="inline-flex items-center gap-2 rounded-lg px-4 py-2 text-sm font-medium text-white transition-colors hover:opacity-90 disabled:opacity-50"
          style={{ backgroundColor: 'var(--martis-accent)' }}
        >
          {saving ? t('updating_password') : t('update_password')}
        </button>
      </form>
    </section>
  )
}
