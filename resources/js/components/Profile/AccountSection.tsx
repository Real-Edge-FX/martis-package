import { useState, type FormEvent } from 'react'
import { useTranslation } from 'react-i18next'
import { InputText } from 'primereact/inputtext'
import { IconField } from 'primereact/iconfield'
import { InputIcon } from 'primereact/inputicon'
import { EnvelopeIcon, LockKeyIcon, UserIcon } from '@phosphor-icons/react'
import { api, ApiError } from '@/lib/api'
import { useToast } from '@/contexts/ToastContext'
import type { ProfileData } from '@/types'

interface AccountSectionProps {
  name: string
  email: string
  /** Receives the profile as the server saved it (the `PATCH` response),
   *  which can differ from the form: a resource may drop or normalise a key. */
  onUpdate: (saved: Partial<ProfileData>) => void
  /** When true, the e-mail is rendered read-only (config
   *  `profile.account.email_editable = false`). The e-mail is often the acting
   *  identity, so a consumer may lock it while name/avatar/password stay editable. */
  emailReadOnly?: boolean
}

export function AccountSection({ name, email, onUpdate, emailReadOnly = false }: AccountSectionProps) {
  const { t } = useTranslation('profile')
  const { addToast } = useToast()
  const [nameVal, setNameVal] = useState(name)
  const [emailVal, setEmailVal] = useState(email)
  const [currentPassword, setCurrentPassword] = useState('')
  const [pendingEmail, setPendingEmail] = useState<string | null>(null)
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [saving, setSaving] = useState(false)

  // A new address is not written at once (v2.4.0): the server asks the
  // current password, mails a confirmation link to the new address and a
  // notice to the old one, and switches it when the link is followed.
  const emailChanged = !emailReadOnly && emailVal.trim().toLowerCase() !== email.trim().toLowerCase()

  async function handleSubmit(e: FormEvent) {
    e.preventDefault()
    setErrors({})
    setSaving(true)
    try {
      const saved = await api.patch<Partial<ProfileData> | null>('/api/profile', {
        name: nameVal,
        email: emailVal,
        ...(emailChanged ? { current_password: currentPassword } : {}),
      })
      onUpdate(saved ?? {})
      if (saved?.pending_email) {
        // Still the current address: the field goes back to it, and the
        // person is told where to look.
        setPendingEmail(saved.pending_email)
        setEmailVal(saved.email ?? email)
        setCurrentPassword('')
        addToast('success', t('email_change_sent', {
          email: saved.pending_email,
          defaultValue: 'Check your inbox: we sent a confirmation link to {{email}}.',
        }))
      } else {
        setPendingEmail(null)
        addToast('success', t('saved'))
      }
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
      aria-labelledby="account-section-title"
    >
      <h2 id="account-section-title" className="text-lg font-semibold martis-text mb-4">
        {t('account')}
      </h2>
      <form onSubmit={(e) => void handleSubmit(e)} noValidate className="space-y-4 max-w-lg">
        <div className="flex flex-col gap-2">
          <label htmlFor="profile-name" className="text-sm font-medium martis-text-muted">
            {t('name')}
          </label>
          <IconField iconPosition="left">
            <InputIcon><UserIcon size={14} /></InputIcon>
            <InputText
              id="profile-name"
              value={nameVal}
              onChange={(e) => setNameVal(e.target.value)}
              invalid={!!errors.name}
              className="w-full"
              required
            />
          </IconField>
          {errors.name && <small className="p-error">{errors.name}</small>}
        </div>

        <div className="flex flex-col gap-2">
          <label htmlFor="profile-email" className="text-sm font-medium martis-text-muted">
            {t('email')}
          </label>
          <IconField iconPosition="left">
            <InputIcon><EnvelopeIcon size={14} /></InputIcon>
            <InputText
              id="profile-email"
              type="email"
              value={emailVal}
              onChange={(e) => setEmailVal(e.target.value)}
              invalid={!!errors.email}
              className="w-full"
              required
              readOnly={emailReadOnly}
              disabled={emailReadOnly}
              aria-readonly={emailReadOnly || undefined}
            />
          </IconField>
          {emailReadOnly && (
            <small className="martis-text-muted">{t('email_locked', { defaultValue: 'Your e-mail cannot be changed.' })}</small>
          )}
          {errors.email && <small className="p-error">{errors.email}</small>}
          {pendingEmail !== null && !emailChanged && (
            <small className="martis-text-muted" role="status" data-testid="email-change-pending">
              {t('email_change_pending', {
                email: pendingEmail,
                defaultValue: 'We sent a confirmation link to {{email}}. Your email address changes when you follow it.',
              })}
            </small>
          )}
        </div>

        {emailChanged && (
          <div className="flex flex-col gap-2">
            <label htmlFor="profile-current-password" className="text-sm font-medium martis-text-muted">
              {t('current_password')}
            </label>
            <IconField iconPosition="left">
              <InputIcon><LockKeyIcon size={14} /></InputIcon>
              <InputText
                id="profile-current-password"
                type="password"
                autoComplete="current-password"
                value={currentPassword}
                onChange={(e) => setCurrentPassword(e.target.value)}
                invalid={!!errors.current_password}
                className="w-full"
                required
              />
            </IconField>
            <small className="martis-text-muted">
              {t('email_change_password_hint', { defaultValue: 'Enter your current password to change your email address.' })}
            </small>
            {errors.current_password && <small className="p-error">{errors.current_password}</small>}
          </div>
        )}

        <button
          type="submit"
          disabled={saving}
          className="inline-flex items-center gap-2 rounded-lg px-4 py-2 text-sm font-medium text-martis-accent-contrast transition-colors hover:opacity-90 disabled:opacity-50"
          style={{ backgroundColor: 'var(--martis-accent)' }}
        >
          {saving ? t('saving') : t('save')}
        </button>
      </form>
    </section>
  )
}
