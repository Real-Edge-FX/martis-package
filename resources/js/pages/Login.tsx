import { useState, type FormEvent } from 'react'
import { Navigate } from 'react-router-dom'
import { useAuth } from '@/contexts/AuthContext'
import { useToast } from '@/contexts/ToastContext'
import { ApiError } from '@/lib/api'
import { useTranslation } from 'react-i18next'
import { Card } from 'primereact/card'
import { InputText } from 'primereact/inputtext'
import { Password } from 'primereact/password'
import { Button } from 'primereact/button'

export function LoginPage() {
  const { user, isLoading, login } = useAuth()
  const { addToast } = useToast()
  const { t } = useTranslation('auth')

  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [submitting, setSubmitting] = useState(false)

  if (!isLoading && user) return <Navigate to="/" replace />

  async function handleSubmit(e: FormEvent) {
    e.preventDefault()
    setErrors({})
    setSubmitting(true)
    try {
      await login(email, password)
    } catch (err) {
      if (err instanceof ApiError && err.errors) {
        const flat: Record<string, string> = {}
        Object.entries(err.errors).forEach(([k, v]) => {
          flat[k] = v[0]?.message ?? String(v[0])
        })
        setErrors(flat)
      } else {
        addToast('error', err instanceof Error ? err.message : t('error'))
      }
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <div className="flex min-h-screen items-center justify-center bg-gray-50 dark:bg-gray-900">
      <Card className="w-full max-w-sm shadow-md">
        <h1 className="mb-6 text-center text-2xl font-bold text-gray-900 dark:text-white">
          {t('title')}
        </h1>

        <form onSubmit={(e) => void handleSubmit(e)} noValidate className="space-y-4">
          <div className="flex flex-col gap-1">
            <label htmlFor="email" className="text-sm font-medium text-gray-700 dark:text-gray-300">
              {t('email')}
            </label>
            <InputText
              id="email"
              type="email"
              autoComplete="email"
              value={email}
              onChange={(e) => setEmail(e.target.value)}
              invalid={!!errors.email}
              className="w-full"
              required
            />
            {errors.email && <small className="text-red-500">{errors.email}</small>}
          </div>

          <div className="flex flex-col gap-1">
            <label htmlFor="password" className="text-sm font-medium text-gray-700 dark:text-gray-300">
              {t('password')}
            </label>
            <Password
              inputId="password"
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              feedback={false}
              toggleMask
              invalid={!!errors.password}
              className="w-full"
              inputClassName="w-full"
              autoComplete="current-password"
              required
            />
            {errors.password && <small className="text-red-500">{errors.password}</small>}
          </div>

          <Button
            type="submit"
            label={submitting ? t('signing_in') : t('sign_in')}
            loading={submitting}
            className="w-full"
          />
        </form>
      </Card>
    </div>
  )
}
