import { useState, type FormEvent } from 'react'
import { useTranslation } from 'react-i18next'
import { InputText } from 'primereact/inputtext'
import { Button } from 'primereact/button'
import { IconField } from 'primereact/iconfield'
import { InputIcon } from 'primereact/inputicon'
import { Envelope, User } from '@phosphor-icons/react'
import { api, ApiError } from '@/lib/api'
import { useToast } from '@/contexts/ToastContext'

interface AccountSectionProps {
  name: string
  email: string
  onUpdate: (name: string, email: string) => void
}

export function AccountSection({ name, email, onUpdate }: AccountSectionProps) {
  const { t } = useTranslation('profile')
  const { addToast } = useToast()
  const [nameVal, setNameVal] = useState(name)
  const [emailVal, setEmailVal] = useState(email)
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [saving, setSaving] = useState(false)

  async function handleSubmit(e: FormEvent) {
    e.preventDefault()
    setErrors({})
    setSaving(true)
    try {
      await api.patch('/api/profile', { name: nameVal, email: emailVal })
      onUpdate(nameVal, emailVal)
      addToast('success', t('saved'))
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
            <InputIcon><User size={14} /></InputIcon>
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
            <InputIcon><Envelope size={14} /></InputIcon>
            <InputText
              id="profile-email"
              type="email"
              value={emailVal}
              onChange={(e) => setEmailVal(e.target.value)}
              invalid={!!errors.email}
              className="w-full"
              required
            />
          </IconField>
          {errors.email && <small className="p-error">{errors.email}</small>}
        </div>

        <Button
          type="submit"
          label={saving ? t('saving') : t('save')}
          loading={saving}
          raised
        />
      </form>
    </section>
  )
}
