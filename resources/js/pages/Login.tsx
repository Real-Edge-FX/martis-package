import { useState, useEffect, type FormEvent, type KeyboardEvent } from "react"
import { Link, useNavigate } from "react-router-dom"
import { useAuth, TwoFactorRequiredError } from "@/contexts/AuthContext"
import { useToast } from "@/contexts/ToastContext"
import { ApiError } from "@/lib/api"
import { config } from "@/lib/config"
import { useAuthCopy } from "@/lib/authCopy"
import { useTranslation } from "react-i18next"
import { ArrowRightIcon, BuildingsIcon, EyeIcon, EyeSlashIcon } from "@phosphor-icons/react"
import { AuthFrame } from "@/components/auth/AuthFrame"
import { FieldError } from "@/components/auth/FieldError"
import { ResourceIcon } from "@/components/ResourceIcon"
import { BASE_PATH } from "@/lib/config"

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
  const tCopy = useAuthCopy()

  const [email, setEmail] = useState("")
  const [password, setPassword] = useState("")
  const [keepSignedIn, setKeepSignedIn] = useState(true)
  const [showPassword, setShowPassword] = useState(false)
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [submitting, setSubmitting] = useState(false)

  const passwordReset = config.auth?.passwordReset
  const registration = config.auth?.registration
  const ssoEnabled = config.auth?.sso?.enabled === true
  const ssoProviders = ssoEnabled
    ? Object.entries(config.auth?.sso?.providers ?? {}).filter(([, p]) => p?.enabled === true)
    : []
  const showDivider = ssoProviders.length > 0
  const showForgot = isFlowEnabled(passwordReset)
  const showRegister = isFlowEnabled(registration)

  // Detect session-expired redirect from api.ts global 401 handler.
  // The toast + history rewrite are deferred to the next macrotask so
  // they do NOT fire synchronously during the first paint. Doing both
  // inside the initial commit can race with the Toast portal mount and
  // confuse browser automation (CDP-based extensions report a
  // "frame detached" the moment `replaceState` lands in the same tick
  // as a portal mount), and the user-visible behaviour is identical.
  useEffect(() => {
    const params = new URLSearchParams(window.location.search)
    if (params.get('expired') !== '1') return

    const handle = window.setTimeout(() => {
      addToast('warning', t('session_expired'))
      const url = new URL(window.location.href)
      url.searchParams.delete('expired')
      window.history.replaceState({}, '', url.toString())
    }, 0)

    return () => window.clearTimeout(handle)
  }, [])

  // Redirect already-authenticated users out of the login page via an
  // effect rather than a render-time `<Navigate>` so the navigation
  // happens outside of React's render cycle. This avoids racing with
  // the auth context's `isLoading` flip on the very first commit, which
  // could otherwise leave the login DOM half-built when a logged-in
  // user lands here directly.
  useEffect(() => {
    if (!isLoading && user) navigate('/', { replace: true })
  }, [isLoading, user, navigate])

  if (!isLoading && user) return null

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

  function handleFlowClick(
    flow: { enabled?: boolean; url?: string | null } | undefined,
    internalPath?: string,
  ) {
    const url = flow?.url?.trim() ?? ''
    if (url) {
      window.location.href = url
      return
    }
    if (internalPath) {
      navigate(internalPath)
      return
    }
    addToast('info', t('sso_not_configured', { defaultValue: 'This sign-in method is not configured on this workspace.' }))
  }

  return (
    <AuthFrame>
      <h2 className="martis-auth-title">
        {tCopy('login', 'title', 'login_title', 'Sign in to your workspace')}
      </h2>
      <p className="martis-auth-sub">
        {showDivider
          ? tCopy('login', 'subtitle_with_sso', 'login_sub_v2', 'Welcome back. Continue with SSO or use your email.')
          : tCopy('login', 'subtitle', 'login_sub', 'Welcome back. Use your email and password to continue.')}
      </p>

      {ssoProviders.map(([providerName, provider], idx) => (
        <button
          key={providerName}
          type="button"
          onClick={() => {
            window.location.href = `${BASE_PATH}/sso/${providerName}/redirect`
          }}
          className="martis-btn-secondary"
          style={{ width: '100%', justifyContent: 'center', height: 40, marginTop: idx === 0 ? 18 : 8 }}
        >
          {provider.icon
            ? <ResourceIcon iconName={provider.icon} size={14} />
            : <BuildingsIcon size={14} />}
          {provider.label ?? t('continue_with', { provider: providerName, defaultValue: `Continue with ${providerName}` })}
        </button>
      ))}

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
            autoComplete="username email"
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
          <FieldError message={errors.email} />
        </div>

        <div style={{ marginBottom: 16 }}>
          <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: 6, alignItems: 'center' }}>
            <label htmlFor="login-password" style={{ fontSize: 13, color: 'var(--martis-text-muted)' }}>
              {t('password')}
            </label>
            {showForgot && (
              <button
                type="button"
                onClick={() => handleFlowClick(passwordReset, '/forgot-password')}
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
          <FieldError message={errors.password} />
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
