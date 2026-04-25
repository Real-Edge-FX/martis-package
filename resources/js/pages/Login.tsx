import { useState, useEffect, type FormEvent, type KeyboardEvent } from "react"
import { Link, Navigate, useNavigate } from "react-router-dom"
import { useAuth, TwoFactorRequiredError } from "@/contexts/AuthContext"
import { useToast } from "@/contexts/ToastContext"
import { ApiError } from "@/lib/api"
import { config } from "@/lib/config"
import { useTranslation } from "react-i18next"
import { ArrowRightIcon, BuildingsIcon, EyeIcon, EyeSlashIcon, GoogleLogoIcon } from "@phosphor-icons/react"
import { AuthFrame } from "@/components/auth/AuthFrame"

/** Tiny helper so the same rule applies to every optional auth flow:
 *  a flow is "active" when the consumer has flipped its `enabled` flag.
 *  The URL is separate so programmers can enable the UI shell first and
 *  wire the destination later — the button shows a toast until then. */
function isFlowEnabled(flow?: { enabled?: boolean }): boolean {
  return flow?.enabled === true
}

export function LoginPage() {
  const { user, isLoading, login } = useAuth()
  const navigate = useNavigate()
  const { addToast } = useToast()
  const { t } = useTranslation("auth")

  const [email, setEmail] = useState("")
  const [password, setPassword] = useState("")
  const [keepSignedIn, setKeepSignedIn] = useState(true)
  const [showPassword, setShowPassword] = useState(false)
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [submitting, setSubmitting] = useState(false)

  const sso = config.auth?.sso
  const google = config.auth?.google
  const passwordReset = config.auth?.passwordReset
  const registration = config.auth?.registration
  const showSso = isFlowEnabled(sso)
  const showGoogle = isFlowEnabled(google)
  const showDivider = showSso || showGoogle
  const showForgot = isFlowEnabled(passwordReset)
  const showRegister = isFlowEnabled(registration)

  // Detect session-expired redirect from api.ts global 401 handler.
  useEffect(() => {
    const params = new URLSearchParams(window.location.search)
    if (params.get('expired') === '1') {
      addToast('warning', t('session_expired'))
      const url = new URL(window.location.href)
      url.searchParams.delete('expired')
      window.history.replaceState({}, '', url.toString())
    }
  }, [])

  if (!isLoading && user) return <Navigate to="/" replace />

  async function handleSubmit(e: FormEvent) {
    e.preventDefault()
    const formData = new FormData(e.currentTarget as HTMLFormElement)
    const submittedEmail = String(formData.get("email") ?? email).trim()
    const submittedPassword = String(formData.get("password") ?? password)

    setEmail(submittedEmail)
    setPassword(submittedPassword)
    setErrors({})
    setSubmitting(true)
    try {
      await login(submittedEmail, submittedPassword)
    } catch (err) {
      if (err instanceof TwoFactorRequiredError) {
        navigate('/2fa/challenge', { replace: true })
        return
      }
      if (err instanceof ApiError) {
        addToast("error", err.message || t("error"))
        if (err.status === 422 && err.errors) {
          setErrors(err.errorsByField())
        }
      } else {
        addToast("error", err instanceof Error ? err.message : t("error"))
      }
    } finally {
      setSubmitting(false)
    }
  }

  function handleFieldKeyDown(e: KeyboardEvent<HTMLInputElement>) {
    if (e.key !== "Enter" || submitting) return
    e.preventDefault()
    e.currentTarget.form?.requestSubmit()
  }

  function handleFlowClick(flow: { enabled?: boolean; url?: string | null } | undefined) {
    const url = flow?.url?.trim() ?? ''
    if (url) {
      window.location.href = url
      return
    }
    addToast('info', t('sso_not_configured', { defaultValue: 'This sign-in method is not configured on this workspace.' }))
  }

  return (
    <AuthFrame>
      <h2 className="martis-auth-title">{t('login_title', { defaultValue: 'Sign in to your workspace' })}</h2>
      <p className="martis-auth-sub">
        {showDivider
          ? t('login_sub_v2', { defaultValue: 'Welcome back. Continue with SSO or use your email.' })
          : t('login_sub', { defaultValue: 'Welcome back. Use your email and password to continue.' })}
      </p>

      {showSso && (
        <button
          type="button"
          onClick={() => handleFlowClick(sso)}
          className="martis-btn-secondary"
          style={{ width: '100%', justifyContent: 'center', height: 40, marginTop: 18 }}
        >
          <BuildingsIcon size={14} />
          {t('continue_with_sso', { defaultValue: 'Continue with SSO' })}
        </button>
      )}
      {showGoogle && (
        <button
          type="button"
          onClick={() => handleFlowClick(google)}
          className="martis-btn-secondary"
          style={{ width: '100%', justifyContent: 'center', height: 40, marginTop: showSso ? 8 : 18 }}
        >
          <GoogleLogoIcon size={14} />
          {t('continue_with_google', { defaultValue: 'Continue with Google' })}
        </button>
      )}

      {showDivider && (
        <div className="martis-auth-divider">
          <span>{t('divider_or', { defaultValue: 'or' })}</span>
        </div>
      )}

      <form
        onSubmit={(e) => void handleSubmit(e)}
        noValidate
        style={{ marginTop: showDivider ? 0 : 20 }}
      >
        <div style={{ marginBottom: 12 }}>
          <label
            htmlFor="login-email"
            style={{ display: 'block', fontSize: 13, color: 'var(--martis-text-muted)', marginBottom: 6 }}
          >
            {t('email')}
          </label>
          <input
            id="login-email"
            type="email"
            name="email"
            autoComplete="email"
            value={email}
            onChange={(e) => setEmail(e.target.value)}
            onKeyDown={handleFieldKeyDown}
            placeholder={t('email_placeholder')}
            required
            className="w-full rounded-md py-2 px-3 text-sm focus:outline-none focus:ring-1"
            style={{
              backgroundColor: 'var(--martis-input-bg)',
              border: `1px solid ${errors.email ? 'var(--martis-danger)' : 'var(--martis-border)'}`,
              color: 'var(--martis-text)',
            }}
          />
          {errors.email && (
            <p style={{ marginTop: 6, fontSize: 12, color: 'var(--martis-danger)' }}>{errors.email}</p>
          )}
        </div>

        <div style={{ marginBottom: 16 }}>
          <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: 6, alignItems: 'center' }}>
            <label htmlFor="login-password" style={{ fontSize: 13, color: 'var(--martis-text-muted)' }}>
              {t('password')}
            </label>
            {showForgot && (
              <button
                type="button"
                onClick={() => handleFlowClick(passwordReset)}
                className="martis-auth-forgot"
              >
                {t('forgot_password', { defaultValue: 'Forgot?' })}
              </button>
            )}
          </div>
          <div style={{ position: 'relative' }}>
            <input
              id="login-password"
              type={showPassword ? 'text' : 'password'}
              name="password"
              autoComplete="current-password"
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              onKeyDown={handleFieldKeyDown}
              placeholder={t('password_placeholder')}
              required
              className="w-full rounded-md py-2 pl-3 pr-10 text-sm focus:outline-none focus:ring-1"
              style={{
                backgroundColor: 'var(--martis-input-bg)',
                border: `1px solid ${errors.password ? 'var(--martis-danger)' : 'var(--martis-border)'}`,
                color: 'var(--martis-text)',
              }}
            />
            <button
              type="button"
              onClick={() => setShowPassword((s) => !s)}
              tabIndex={-1}
              aria-label={showPassword ? t('hide_password') : t('show_password')}
              style={{
                position: 'absolute',
                right: 8,
                top: '50%',
                transform: 'translateY(-50%)',
                background: 'none',
                border: 0,
                cursor: 'pointer',
                color: 'var(--martis-text-muted)',
                display: 'inline-flex',
                alignItems: 'center',
                padding: 4,
              }}
            >
              {showPassword ? <EyeSlashIcon size={16} /> : <EyeIcon size={16} />}
            </button>
          </div>
          {errors.password && (
            <p style={{ marginTop: 6, fontSize: 12, color: 'var(--martis-danger)' }}>{errors.password}</p>
          )}
        </div>

        <label className="martis-auth-toggle" style={{ marginBottom: 16 }}>
          <input
            type="checkbox"
            checked={keepSignedIn}
            onChange={(e) => setKeepSignedIn(e.target.checked)}
          />
          <span className="martis-auth-toggle-track" aria-hidden="true" />
          <span>{t('keep_signed_in', { defaultValue: 'Keep me signed in on this device' })}</span>
        </label>

        <button
          type="submit"
          disabled={submitting}
          className="martis-btn-primary"
          style={{ width: '100%', justifyContent: 'center', height: 40 }}
        >
          {submitting ? t('signing_in') : t('sign_in')}
          {!submitting && <ArrowRightIcon size={14} weight="bold" />}
        </button>

        {showRegister && (
          <div style={{ textAlign: 'center', marginTop: 18, fontSize: 13, color: 'var(--martis-text-muted)' }}>
            {t('register_prompt', { defaultValue: "Don't have an account?" })}{' '}
            {registration?.url?.trim() ? (
              <a
                href={registration.url}
                style={{ color: 'var(--martis-accent)', textDecoration: 'none' }}
              >
                {t('register_link', { defaultValue: 'Create an account' })}
              </a>
            ) : (
              <Link
                to="/register"
                style={{ color: 'var(--martis-accent)', textDecoration: 'none' }}
              >
                {t('register_link', { defaultValue: 'Create an account' })}
              </Link>
            )}
          </div>
        )}
      </form>
    </AuthFrame>
  )
}
