import { useState, type FormEvent } from "react"
import { Navigate } from "react-router-dom"
import { useAuth } from "@/contexts/AuthContext"
import { useToast } from "@/contexts/ToastContext"
import { ApiError } from "@/lib/api"
import { useTranslation } from "react-i18next"
import { InputText } from "primereact/inputtext"
import { Button } from "primereact/button"
import { IconField } from "primereact/iconfield"
import { InputIcon } from "primereact/inputicon"

function getBrand(): string {
  return window.MartisConfig?.brand ?? "Martis"
}

export function LoginPage() {
  const { user, isLoading, login } = useAuth()
  const { addToast } = useToast()
  const { t } = useTranslation("auth")

  const [email, setEmail] = useState("")
  const [password, setPassword] = useState("")
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
        addToast("error", err instanceof Error ? err.message : t("error"))
      }
    } finally {
      setSubmitting(false)
    }
  }

  const brand = getBrand()

  return (
    <div className="flex min-h-screen">
      {/* Left panel — brand accent */}
      <div className="hidden lg:flex lg:w-1/2 items-center justify-center bg-gradient-to-br from-indigo-600 via-indigo-500 to-purple-600">
        <div className="text-center text-white px-12">
          <div className="mb-6 flex items-center justify-center">
            <div className="flex h-16 w-16 items-center justify-center rounded-2xl bg-white/20 backdrop-blur-sm">
              <i className="pi pi-shield text-3xl text-white" />
            </div>
          </div>
          <h2 className="text-3xl font-bold mb-3">{brand}</h2>
          <p className="text-indigo-100 text-lg">Administration Panel</p>
        </div>
      </div>

      {/* Right panel — login form */}
      <div className="flex flex-1 items-center justify-center bg-white dark:bg-gray-950 px-6">
        <div className="w-full max-w-md">
          {/* Mobile brand header */}
          <div className="lg:hidden mb-8 text-center">
            <div className="mb-4 inline-flex h-14 w-14 items-center justify-center rounded-2xl bg-indigo-600">
              <i className="pi pi-shield text-2xl text-white" />
            </div>
            <h1 className="text-2xl font-bold text-gray-900 dark:text-white">{brand}</h1>
          </div>

          <div className="hidden lg:block mb-8">
            <h1 className="text-2xl font-bold text-gray-900 dark:text-white">{t("title")}</h1>
            <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">
              Enter your credentials to access the admin panel.
            </p>
          </div>

          <form onSubmit={(e) => void handleSubmit(e)} noValidate className="space-y-5">
            <div className="flex flex-col gap-2">
              <label htmlFor="email" className="text-sm font-semibold text-gray-700 dark:text-gray-300">
                {t("email")}
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
              <label htmlFor="password" className="text-sm font-semibold text-gray-700 dark:text-gray-300">
                {t("password")}
              </label>
              <div className="relative">
                <IconField iconPosition="left">
                  <InputIcon className="pi pi-lock" />
                  <InputText
                    id="password"
                    type={showPassword ? "text" : "password"}
                    autoComplete="current-password"
                    value={password}
                    onChange={(e) => setPassword(e.target.value)}
                    invalid={!!errors.password}
                    className="w-full pr-10"
                    placeholder="Enter your password"
                    required
                  />
                </IconField>
                <span
                  role="button"
                  tabIndex={-1}
                  onClick={() => setShowPassword(!showPassword)}
                  onKeyDown={(e) => { if (e.key === "Enter" || e.key === " ") setShowPassword(!showPassword) }}
                  className="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 cursor-pointer select-none"
                  aria-label={showPassword ? "Hide password" : "Show password"}
                >
                  <i className={`pi ${showPassword ? "pi-eye-slash" : "pi-eye"} text-sm`} />
                </span>
              </div>
              {errors.password && <small className="p-error">{errors.password}</small>}
            </div>

            <Button
              type="submit"
              label={submitting ? t("signing_in") : t("sign_in")}
              icon={submitting ? undefined : "pi pi-sign-in"}
              loading={submitting}
              className="w-full mt-2"
            />
          </form>

          <p className="mt-8 text-center text-xs text-gray-400 dark:text-gray-600">
            Powered by {brand}
          </p>
        </div>
      </div>
    </div>
  )
}
