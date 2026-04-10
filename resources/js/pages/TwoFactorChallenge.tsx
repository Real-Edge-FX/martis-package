import { useState, useRef, useEffect, type FormEvent } from 'react'
import { useTranslation } from 'react-i18next'
import { InputText } from 'primereact/inputtext'
import { Button } from 'primereact/button'
import { api, ApiError } from '@/lib/api'
import { useToast } from '@/contexts/ToastContext'
import { config, BASE_PATH } from '@/lib/config'
import logoSrc from '@images/logo.png'

/** After this many milliseconds of inactivity, expire the challenge session. */
const CHALLENGE_TIMEOUT_MS = 5 * 60 * 1000 // 5 minutes

function getBrand(): string {
  return config.brand ?? 'Martis'
}

export function TwoFactorChallengePage() {
  const { t } = useTranslation('profile')
  const { addToast } = useToast()
  const [useRecovery, setUseRecovery] = useState(false)
  const [code, setCode] = useState('')
  const [submitting, setSubmitting] = useState(false)
  const [cancelling, setCancelling] = useState(false)
  const [error, setError] = useState('')
  const inputRef = useRef<HTMLInputElement>(null)

  const brand = getBrand()

  // Auto-expire the challenge session after CHALLENGE_TIMEOUT_MS
  useEffect(() => {
    const timer = setTimeout(() => {
      void handleCancel(true)
    }, CHALLENGE_TIMEOUT_MS)
    return () => clearTimeout(timer)
  }, [])

  async function handleCancel(expired = false) {
    setCancelling(true)
    try {
      await api.post('/api/auth/logout')
    } catch {
      // ignore
    }
    if (expired) {
      addToast('info', t('2fa_session_expired'))
    }
    window.location.href = BASE_PATH + '/login'
  }

  async function handleSubmit(e: FormEvent) {
    e.preventDefault()
    setError('')
    setSubmitting(true)
    try {
      await api.post('/api/2fa/challenge', {
        code,
        use_recovery_code: useRecovery,
      })
      // Full page reload so AuthContext re-fetches the authenticated user
      // (React Router navigation would reuse the stale user=null state)
      window.location.href = BASE_PATH + '/'
    } catch (err) {
      if (err instanceof ApiError) {
        setError(t('2fa_challenge_failed'))
      } else {
        addToast('error', t('error'))
      }
      setSubmitting(false)
    }
  }

  function toggleMode() {
    setUseRecovery(!useRecovery)
    setCode('')
    setError('')
    setTimeout(() => inputRef.current?.focus(), 50)
  }

  return (
    <div className="martis-bg flex min-h-screen items-center justify-center">
      <div className="w-full max-w-sm">
        <div className="mb-8 text-center">
          <img
            src={logoSrc}
            alt={brand}
            className="mx-auto h-16 w-auto object-contain"
            style={{ maxWidth: 280 }}
          />
        </div>

        <div className="martis-card-bg rounded-xl p-6 border martis-border">
          <h1 className="text-xl font-bold martis-text mb-2 text-center">
            {t('2fa_challenge_title')}
          </h1>
          <p className="text-sm martis-text-muted text-center mb-6">
            {useRecovery ? t('2fa_recovery_instructions') : t('2fa_challenge_instructions')}
          </p>

          <form onSubmit={(e) => void handleSubmit(e)} noValidate className="space-y-5">
            <div className="flex flex-col gap-2">
              <label htmlFor="2fa-challenge-code" className="text-sm font-medium martis-text-muted">
                {useRecovery ? t('2fa_recovery_placeholder') : t('2fa_code_label')}
              </label>
              <InputText
                id="2fa-challenge-code"
                ref={inputRef}
                value={code}
                onChange={(e) => {
                  const val = useRecovery
                    ? e.target.value
                    : e.target.value.replace(/\D/g, '').slice(0, 6)
                  setCode(val)
                  setError('')
                }}
                maxLength={useRecovery ? undefined : 6}
                inputMode={useRecovery ? 'text' : 'numeric'}
                autoComplete={useRecovery ? 'off' : 'one-time-code'}
                autoFocus
                invalid={!!error}
                placeholder={useRecovery ? t('2fa_recovery_placeholder') : t('2fa_otp_placeholder')}
                className={`w-full ${useRecovery ? '' : 'text-center text-lg font-mono tracking-widest'}`}
                required
              />
              {error && <small className="p-error">{error}</small>}
            </div>

            <Button
              type="submit"
              label={submitting ? t('2fa_challenge_submitting') : t('2fa_challenge_submit')}
              loading={submitting}
              disabled={cancelling}
              className="w-full"
              raised
              style={{ padding: '0.875rem 1.5rem', fontSize: '1rem', fontWeight: 700 }}
            />

            <Button
              type="button"
              label={t('2fa_cancel')}
              loading={cancelling}
              disabled={submitting}
              severity="secondary"
              text
              className="w-full"
              style={{ padding: '0.5rem 1rem', fontSize: '0.9rem' }}
              onClick={() => void handleCancel()}
            />
          </form>

          <div className="mt-4 text-center">
            <button
              type="button"
              className="text-sm cursor-pointer border-0 bg-transparent martis-text-muted hover:opacity-80 underline"
              onClick={toggleMode}
            >
              {useRecovery ? t('2fa_use_otp') : t('2fa_use_recovery_code')}
            </button>
          </div>
        </div>

        <p className="mt-6 text-center text-xs martis-text-muted" style={{ opacity: 0.5 }}>
          Powered by {brand}
        </p>
      </div>
    </div>
  )
}
