import { useState, type FormEvent } from 'react'
import { Navigate } from 'react-router-dom'
import { useAuth } from '@/contexts/AuthContext'
import { useToast } from '@/contexts/ToastContext'
import { ApiError } from '@/lib/api'
import { useTranslation } from 'react-i18next'
import { InputText } from 'primereact/inputtext'
import { Button } from 'primereact/button'
import { IconField } from 'primereact/iconfield'
import { InputIcon } from 'primereact/inputicon'

function getBrand(): string {
  return window.MartisConfig?.brand ?? 'Martis'
}

export function LoginPage() {
  const { user, isLoading, login } = useAuth()
  const { addToast } = useToast()
  const { t } = useTranslation('auth')

  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [showPassword, setShowPassword] = useState(false)
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

  const brand = getBrand()

  return (
    <div className="flex min-h-screen items-center justify-center" style={{ backgroundColor: '#1b2332' }}>
      <div className="w-full max-w-sm">
        {/* Brand */}
        <div className="mb-8 text-center">
          <div className="mb-4 inline-flex h-14 w-14 items-center justify-center rounded-2xl bg-indigo-500/20">
            <i className="pi pi-shield text-2xl text-indigo-400" />
          </div>
          <h1 className="text-xl font-bold text-white">{brand}</h1>
        </div>

        {/* Form card */}
        <div className="rounded-xl p-6" style={{ backgroundColor: '#1e293b', border: '1px solid #334155' }}>
          <form onSubmit={(e) => void handleSubmit(e)} noValidate className="space-y-5">
            <div className="flex flex-col gap-2">
              <label htmlFor="email" className="text-sm font-medium text-slate-300">
                {t('email')}
              </label>
              <IconField iconPosition="left">
                <InputIcon className="pi pi-envelope" />
                <InputText
                  id="email"
                  type="email"
                  autoComplete="email"
                  value={email}
                  onChange={(e) => setEmail(e.target.value)}
                  invalid={!!errors.email}
                  className="w-full"
                  placeholder="admin@example.com"
                  required
                />
              </IconField>
              {errors.email && <small className="p-error">{errors.email}</small>}
            </div>

            <div className="flex flex-col gap-2">
              <label htmlFor="password" className="text-sm font-medium text-slate-300">
                {t('password')}
              </label>
              <div className="relative">
                <IconField iconPosition="left">
                  <InputIcon className="pi pi-lock" />
                  <InputText
                    id="password"
                    type={showPassword ? 'text' : 'password'}
                    autoComplete="current-password"
                    value={password}
                    onChange={(e) => setPassword(e.target.value)}
                    invalid={!!errors.password}
                    className="w-full"
                    placeholder="Enter your password"
                    required
                  />
                </IconField>
                <button
                  type="button"
                  onClick={() => setShowPassword(!showPassword)}
                  className="absolute right-3 top-1/2 -translate-y-1/2 text-slate-500 hover:text-slate-300 focus:outline-none"
                  tabIndex={-1}
                  aria-label={showPassword ? 'Hide password' : 'Show password'}
                >
                  <i className={showPassword ? 'pi pi-eye-slash' : 'pi pi-eye'} />
                </button>
              </div>
              {errors.password && <small className="p-error">{errors.password}</small>}
            </div>

            <Button
              type="submit"
              label={submitting ? t('signing_in') : t('sign_in')}
              icon={submitting ? undefined : 'pi pi-sign-in'}
              loading={submitting}
              className="w-full"
            />
          </form>
        </div>

        <p className="mt-6 text-center text-xs text-slate-600">
          Powered by {brand}
        </p>
      </div>
    </div>
  )
}
