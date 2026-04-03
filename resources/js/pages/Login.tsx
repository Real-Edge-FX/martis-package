import { useState, type FormEvent } from 'react'
import { Navigate } from 'react-router-dom'
import { useAuth } from '@/contexts/AuthContext'
import { useToast } from '@/contexts/ToastContext'
import { ApiError } from '@/lib/api'
import { config } from '@/lib/config'
import { useTranslation } from 'react-i18next'
import { InputText } from 'primereact/inputtext'
import { Button } from 'primereact/button'
import { IconField } from 'primereact/iconfield'
import { InputIcon } from 'primereact/inputicon'
import logoSrc from '@images/logo.png'

function getBrand(): string {
  return config.brand ?? 'Martis'
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
    <div className="martis-bg flex min-h-screen items-center justify-center">
      <div className="w-full max-w-sm">
        {/* Brand logo */}
        <div className="mb-8 text-center">
          <img
            src={logoSrc}
            alt={brand}
            className="mx-auto h-16 w-auto object-contain"
            style={{ maxWidth: 280 }}
          />
        </div>

        {/* Form card */}
        <div className="martis-card-bg rounded-xl p-6 border martis-border">
          <form onSubmit={(e) => void handleSubmit(e)} noValidate className="space-y-5">
            <div className="flex flex-col gap-2">
              <label htmlFor="email" className="text-sm font-medium martis-text-muted">
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
              <label htmlFor="password" className="text-sm font-medium martis-text-muted">
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
                  className="absolute right-3 top-1/2 -translate-y-1/2 martis-text-muted hover:opacity-80 focus:outline-none bg-transparent border-0 cursor-pointer p-0"
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

        <p className="mt-6 text-center text-xs martis-text-muted" style={{ opacity: 0.5 }}>
          Powered by {brand}
        </p>
      </div>
    </div>
  )
}
