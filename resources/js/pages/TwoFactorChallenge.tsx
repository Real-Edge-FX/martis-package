import { useState, useRef, useEffect, type FormEvent, type ClipboardEvent, type KeyboardEvent } from 'react'
import { useTranslation } from 'react-i18next'
import { ArrowLeftIcon, ArrowRightIcon } from '@phosphor-icons/react'
import { api, ApiError } from '@/lib/api'
import { useToast } from '@/contexts/ToastContext'
import { useAuth } from '@/contexts/AuthContext'
import { BASE_PATH } from '@/lib/config'
import { AuthFrame } from '@/components/auth/AuthFrame'

/** Inactivity timeout for the challenge session — matches the backend TTL. */
const CHALLENGE_TIMEOUT_MS = 5 * 60 * 1000
/** Countdown displayed in the body while the code is valid. Visual only. */
const CODE_VALID_SECONDS = 30
const OTP_LENGTH = 6

function formatCountdown(secondsLeft: number): string {
  const clamped = Math.max(0, secondsLeft)
  const m = String(Math.floor(clamped / 60)).padStart(2, '0')
  const s = String(clamped % 60).padStart(2, '0')
  return `${m}:${s}`
}

export function TwoFactorChallengePage() {
  const { t } = useTranslation('profile')
  const { addToast } = useToast()
  const { user } = useAuth()

  const [useRecovery, setUseRecovery] = useState(false)
  const [digits, setDigits] = useState<string[]>(() => Array(OTP_LENGTH).fill(''))
  const [recoveryCode, setRecoveryCode] = useState('')
  const [submitting, setSubmitting] = useState(false)
  const [cancelling, setCancelling] = useState(false)
  const [error, setError] = useState('')
  const [secondsLeft, setSecondsLeft] = useState(CODE_VALID_SECONDS)

  const otpRefs = useRef<Array<HTMLInputElement | null>>([])
  const recoveryRef = useRef<HTMLInputElement | null>(null)

  // Auto-expire the challenge after CHALLENGE_TIMEOUT_MS — matches the
  // backend TTL and logs the user out if they leave the tab idle.
  useEffect(() => {
    const timer = setTimeout(() => {
      void handleCancel(true)
    }, CHALLENGE_TIMEOUT_MS)
    return () => clearTimeout(timer)
  }, [])

  // Visual code countdown — decrements every second while the user is on
  // the OTP mode, resets when switching between modes.
  useEffect(() => {
    if (useRecovery) return
    setSecondsLeft(CODE_VALID_SECONDS)
    const interval = setInterval(() => {
      setSecondsLeft((s) => (s > 0 ? s - 1 : CODE_VALID_SECONDS))
    }, 1000)
    return () => clearInterval(interval)
  }, [useRecovery])

  // Focus the first OTP cell on mount and whenever the user toggles back
  // to OTP mode from the recovery-code path.
  useEffect(() => {
    if (useRecovery) {
      setTimeout(() => recoveryRef.current?.focus(), 50)
    } else {
      setTimeout(() => otpRefs.current[0]?.focus(), 50)
    }
  }, [useRecovery])

  async function handleCancel(expired = false) {
    setCancelling(true)
    try {
      await api.post('/api/auth/logout')
    } catch {
      /* ignore */
    }
    if (expired) {
      addToast('info', t('2fa_session_expired'))
    }
    window.location.href = BASE_PATH + '/login'
  }

  async function handleSubmit(e?: FormEvent) {
    if (e) e.preventDefault()
    if (submitting) return
    const code = useRecovery ? recoveryCode.trim() : digits.join('')
    if (!code || (!useRecovery && code.length !== OTP_LENGTH)) {
      setError(t('2fa_invalid_code'))
      return
    }
    setError('')
    setSubmitting(true)
    try {
      await api.post('/api/2fa/challenge', {
        code,
        use_recovery_code: useRecovery,
      })
      // Full page reload so AuthContext re-fetches the authenticated user.
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

  function handleDigitChange(index: number, raw: string) {
    const onlyDigits = raw.replace(/\D/g, '')
    if (onlyDigits.length === 0) {
      setDigits((prev) => {
        const next = [...prev]
        next[index] = ''
        return next
      })
      return
    }
    // If the field received more than one digit (autofill or paste-like
    // input events), distribute across the next cells rather than
    // clipping — users expect the 6-digit code to "fall into" all boxes.
    setDigits((prev) => {
      const next = [...prev]
      for (let i = 0; i < onlyDigits.length && index + i < OTP_LENGTH; i++) {
        next[index + i] = onlyDigits[i]!
      }
      return next
    })
    const advanceTo = Math.min(index + onlyDigits.length, OTP_LENGTH - 1)
    otpRefs.current[advanceTo]?.focus()
    otpRefs.current[advanceTo]?.select()
    setError('')

    // Auto-submit once the last cell is filled.
    if (index + onlyDigits.length >= OTP_LENGTH) {
      setTimeout(() => void handleSubmit(), 50)
    }
  }

  function handleDigitKeyDown(index: number, e: KeyboardEvent<HTMLInputElement>) {
    if (e.key === 'Backspace' && !digits[index] && index > 0) {
      otpRefs.current[index - 1]?.focus()
      return
    }
    if (e.key === 'ArrowLeft' && index > 0) {
      e.preventDefault()
      otpRefs.current[index - 1]?.focus()
      return
    }
    if (e.key === 'ArrowRight' && index < OTP_LENGTH - 1) {
      e.preventDefault()
      otpRefs.current[index + 1]?.focus()
    }
  }

  function handleOtpPaste(e: ClipboardEvent<HTMLInputElement>) {
    const text = e.clipboardData.getData('text').trim()
    if (!/^\d+$/.test(text)) return
    e.preventDefault()
    const sliced = text.slice(0, OTP_LENGTH).split('')
    setDigits((prev) => {
      const next = [...prev]
      for (let i = 0; i < OTP_LENGTH; i++) next[i] = sliced[i] ?? ''
      return next
    })
    const last = Math.min(sliced.length, OTP_LENGTH) - 1
    otpRefs.current[last >= 0 ? last : 0]?.focus()
    setError('')
    if (sliced.length >= OTP_LENGTH) {
      setTimeout(() => void handleSubmit(), 50)
    }
  }

  function toggleMode() {
    setUseRecovery((v) => !v)
    setDigits(Array(OTP_LENGTH).fill(''))
    setRecoveryCode('')
    setError('')
  }

  const emailLabel = user?.email ?? ''

  return (
    <AuthFrame>
      <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginBottom: 14 }}>
        <button
          type="button"
          onClick={() => void handleCancel()}
          disabled={cancelling || submitting}
          className="martis-auth-back"
          aria-label={t('2fa_cancel')}
        >
          <ArrowLeftIcon size={14} />
        </button>
        {emailLabel && (
          <span style={{ fontSize: 13, color: 'var(--martis-text-muted)' }}>{emailLabel}</span>
        )}
      </div>

      <h2 className="martis-auth-title">
        {useRecovery
          ? t('2fa_recovery_title', { defaultValue: 'Use a backup code' })
          : t('2fa_challenge_title_v2', { defaultValue: 'Enter the 6-digit code' })}
      </h2>
      <p className="martis-auth-sub">
        {useRecovery ? t('2fa_recovery_instructions') : t('2fa_challenge_instructions')}
      </p>

      <form onSubmit={(e) => void handleSubmit(e)} noValidate>
        {useRecovery ? (
          <div style={{ marginTop: 20 }}>
            <label
              htmlFor="2fa-recovery"
              style={{ display: 'block', fontSize: 13, color: 'var(--martis-text-muted)', marginBottom: 6 }}
            >
              {t('2fa_recovery_placeholder')}
            </label>
            <input
              id="2fa-recovery"
              ref={recoveryRef}
              type="text"
              inputMode="text"
              autoComplete="off"
              value={recoveryCode}
              onChange={(e) => { setRecoveryCode(e.target.value); setError('') }}
              placeholder={t('2fa_recovery_placeholder')}
              className="w-full rounded-md py-2 px-3 text-sm focus:outline-none focus:ring-1"
              style={{
                backgroundColor: 'var(--martis-input-bg)',
                border: `1px solid ${error ? 'var(--martis-danger)' : 'var(--martis-border)'}`,
                color: 'var(--martis-text)',
                fontFamily: 'var(--martis-font-mono)',
              }}
            />
          </div>
        ) : (
          <div className="martis-auth-otp-row">
            {digits.map((digit, i) => (
              <input
                key={i}
                ref={(el) => { otpRefs.current[i] = el }}
                type="text"
                inputMode="numeric"
                autoComplete={i === 0 ? 'one-time-code' : 'off'}
                maxLength={1}
                value={digit}
                onChange={(e) => handleDigitChange(i, e.target.value)}
                onKeyDown={(e) => handleDigitKeyDown(i, e)}
                onPaste={i === 0 ? handleOtpPaste : undefined}
                disabled={submitting}
                aria-label={t('2fa_otp_digit', { n: i + 1, defaultValue: `Digit ${i + 1}` })}
                className="martis-auth-otp-input"
              />
            ))}
          </div>
        )}
        {error && (
          <p style={{ marginTop: 10, fontSize: 12, color: 'var(--martis-danger)', textAlign: 'center' }}>
            {error}
          </p>
        )}

        <div
          style={{
            display: 'flex',
            justifyContent: 'space-between',
            alignItems: 'center',
            marginTop: 20,
            fontSize: 13,
            color: 'var(--martis-text-muted)',
          }}
        >
          {!useRecovery && (
            <span>
              {t('2fa_code_expires_in', { defaultValue: 'Code expires in' })}{' '}
              <span style={{ fontFamily: 'var(--martis-font-mono)', color: 'var(--martis-text)' }}>
                {formatCountdown(secondsLeft)}
              </span>
            </span>
          )}
          <button
            type="button"
            onClick={toggleMode}
            className="martis-auth-forgot"
            style={{ background: 'none', border: 0, padding: 0, cursor: 'pointer', marginLeft: 'auto' }}
          >
            {useRecovery ? t('2fa_use_otp') : t('2fa_use_recovery_code')}
          </button>
        </div>

        <button
          type="submit"
          disabled={submitting || cancelling}
          className="martis-btn-primary"
          style={{ width: '100%', justifyContent: 'center', height: 40, marginTop: 20 }}
        >
          {submitting ? t('2fa_challenge_submitting') : t('2fa_challenge_submit')}
          {!submitting && <ArrowRightIcon size={14} weight="bold" />}
        </button>
      </form>
    </AuthFrame>
  )
}
