import { useState, type FormEvent } from 'react'
import { useTranslation } from 'react-i18next'
import { InputText } from 'primereact/inputtext'
import { IconField } from 'primereact/iconfield'
import { InputIcon } from 'primereact/inputicon'
import { Lock } from '@phosphor-icons/react'
import { api, ApiError } from '@/lib/api'
import { useToast } from '@/contexts/ToastContext'

export function PasswordSection() {
  const { t } = useTranslation('profile')
  const { addToast } = useToast()
  const [current, setCurrent] = useState('')
  const [next, setNext] = useState('')
  const [confirm, setConfirm] = useState('')
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [saving, setSaving] = useState(false)

  async function handleSubmit(e: FormEvent) {
    e.preventDefault()
    const localErrors: Record<string, string> = {}

    if (next.length < 8) localErrors.new_password = t('password_min')
    if (next !== confirm) localErrors.confirm_password = t('password_mismatch')
    if (Object.keys(localErrors).length > 0) {
      setErrors(localErrors)
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
      if (err instanceof ApiError && err.errors) {
        setErrors(err.errorsByField())
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
        <div className="flex flex-col gap-2">
          <label htmlFor="current-password" className="text-sm font-medium martis-text-muted">
            {t('current_password')}
          </label>
          <IconField iconPosition="left">
            <InputIcon><Lock size={14} /></InputIcon>
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
          <label htmlFor="new-password" className="text-sm font-medium martis-text-muted">
            {t('new_password')}
          </label>
          <IconField iconPosition="left">
            <InputIcon><Lock size={14} /></InputIcon>
            <InputText
              id="new-password"
              type="password"
              value={next}
              onChange={(e) => setNext(e.target.value)}
              invalid={!!errors.new_password}
              className="w-full"
              autoComplete="new-password"
              required
            />
          </IconField>
          {errors.new_password && <small className="p-error">{errors.new_password}</small>}
        </div>

        <div className="flex flex-col gap-2">
          <label htmlFor="confirm-password" className="text-sm font-medium martis-text-muted">
            {t('confirm_password')}
          </label>
          <IconField iconPosition="left">
            <InputIcon><Lock size={14} /></InputIcon>
            <InputText
              id="confirm-password"
              type="password"
              value={confirm}
              onChange={(e) => setConfirm(e.target.value)}
              invalid={!!errors.confirm_password}
              className="w-full"
              autoComplete="new-password"
              required
            />
          </IconField>
          {errors.confirm_password && <small className="p-error">{errors.confirm_password}</small>}
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
