import { useState, useEffect, useRef } from 'react'
import { useTranslation } from 'react-i18next'
import { Dialog } from 'primereact/dialog'
import { Button } from 'primereact/button'
import { InputText } from 'primereact/inputtext'
import { Copy, Check } from '@phosphor-icons/react'
import { api, ApiError } from '@/lib/api'
import { useToast } from '@/contexts/ToastContext'

interface TwoFactorSetupData {
  qr_code_svg: string
  secret: string
}

interface TwoFactorWizardProps {
  visible: boolean
  onClose: () => void
  onEnabled: () => void
}

type WizardStep = 'setup' | 'verify' | 'recovery'

export function TwoFactorWizard({ visible, onClose, onEnabled }: TwoFactorWizardProps) {
  const { t } = useTranslation('profile')
  const { addToast } = useToast()
  const [step, setStep] = useState<WizardStep>('setup')
  const [setupData, setSetupData] = useState<TwoFactorSetupData | null>(null)
  const [loadingSetup, setLoadingSetup] = useState(false)
  const [otp, setOtp] = useState('')
  const [verifying, setVerifying] = useState(false)
  const [otpError, setOtpError] = useState('')
  const [recoveryCodes, setRecoveryCodes] = useState<string[]>([])
  const [copied, setCopied] = useState(false)
  const otpRef = useRef<HTMLInputElement>(null)

  // Load setup data when modal opens
  useEffect(() => {
    if (!visible) {
      // Reset state on close
      setStep('setup')
      setSetupData(null)
      setOtp('')
      setOtpError('')
      setRecoveryCodes([])
      setCopied(false)
      return
    }

    async function fetchSetup() {
      setLoadingSetup(true)
      try {
        const data = await api.post<TwoFactorSetupData>('/api/profile/2fa/setup')
        setSetupData(data)
      } catch {
        addToast('error', t('error'))
        onClose()
      } finally {
        setLoadingSetup(false)
      }
    }

    void fetchSetup()
  }, [visible])

  useEffect(() => {
    if (step === 'verify') {
      setTimeout(() => otpRef.current?.focus(), 100)
    }
  }, [step])

  async function handleVerify() {
    if (otp.length !== 6) {
      setOtpError(t('2fa_invalid_code'))
      return
    }
    setOtpError('')
    setVerifying(true)
    try {
      const res = await api.post<{ recovery_codes: string[] }>('/api/profile/2fa/confirm', { code: otp })
      setRecoveryCodes(res.recovery_codes ?? [])
      setStep('recovery')
    } catch (err) {
      if (err instanceof ApiError) {
        setOtpError(t('2fa_invalid_code'))
      } else {
        addToast('error', t('error'))
      }
    } finally {
      setVerifying(false)
    }
  }

  async function handleCopyCodes() {
    const text = recoveryCodes.join('\n')
    try {
      await navigator.clipboard.writeText(text)
      setCopied(true)
      setTimeout(() => setCopied(false), 2000)
    } catch {
      // Clipboard not available
    }
  }

  function handleDone() {
    onEnabled()
    onClose()
    addToast('success', t('2fa_enabled_success'))
  }

  const renderSetup = () => (
    <div className="space-y-5">
      <p className="text-sm martis-text-muted">{t('2fa_scan_instructions')}</p>

      {loadingSetup ? (
        <div className="flex items-center justify-center h-40">
          <div className="animate-spin rounded-full h-8 w-8 border-2 border-indigo-500 border-t-transparent" />
        </div>
      ) : setupData ? (
        <>
          <div
            className="flex justify-center p-4 rounded-lg border martis-border"
            style={{ backgroundColor: 'var(--martis-card-bg)' }}
            dangerouslySetInnerHTML={{ __html: setupData.qr_code_svg }}
            aria-label={t('2fa_scan_qr')}
          />
          <div>
            <p className="text-xs font-medium martis-text-muted mb-1">{t('2fa_manual_entry')}</p>
            <code
              className="block p-2 rounded text-xs font-mono break-all selection:bg-primary/20 selection:text-foreground"
              style={{
                backgroundColor: 'var(--martis-hover)',
                color: 'var(--martis-text)',
                border: '1px solid var(--martis-border)',
              }}
            >
              {setupData.secret}
            </code>
          </div>
        </>
      ) : null}

      <div className="flex justify-end">
        <Button
          type="button"
          label={t('2fa_next')}
          onClick={() => setStep('verify')}
          disabled={!setupData}
          raised
        />
      </div>
    </div>
  )

  const renderVerify = () => (
    <div className="space-y-5">
      <p className="text-sm martis-text-muted">{t('2fa_code_instructions')}</p>

      <div className="flex flex-col gap-2">
        <label htmlFor="2fa-otp" className="text-sm font-medium martis-text-muted">
          {t('2fa_code_label')}
        </label>
        <InputText
          id="2fa-otp"
          ref={otpRef}
          value={otp}
          onChange={(e) => {
            const val = e.target.value.replace(/\D/g, '').slice(0, 6)
            setOtp(val)
            setOtpError('')
          }}
          maxLength={6}
          inputMode="numeric"
          autoComplete="one-time-code"
          invalid={!!otpError}
          placeholder={t('2fa_otp_placeholder')}
          className="w-full text-center text-lg font-mono tracking-widest"
        />
        {otpError && <small className="p-error">{otpError}</small>}
      </div>

      <div className="flex justify-between">
        <Button
          type="button"
          label={t('2fa_scan_qr')}
          text
          onClick={() => setStep('setup')}
        />
        <Button
          type="button"
          label={verifying ? t('2fa_verifying') : t('2fa_verify')}
          loading={verifying}
          onClick={() => void handleVerify()}
          raised
        />
      </div>
    </div>
  )

  const renderRecovery = () => (
    <div className="space-y-5">
      <p className="text-sm martis-text-muted">{t('2fa_recovery_instructions')}</p>

      <div
        className="rounded-lg p-4 border martis-border"
        style={{ backgroundColor: 'var(--martis-hover)' }}
      >
        <div className="grid grid-cols-2 gap-1">
          {recoveryCodes.map((code) => (
            <code key={code} className="text-sm font-mono martis-text p-1">
              {code}
            </code>
          ))}
        </div>
      </div>

      <div className="flex justify-between">
        <Button
          type="button"
          icon={copied ? <Check size={16} /> : <Copy size={16} />}
          label={copied ? t('2fa_codes_copied') : t('2fa_copy_codes')}
          outlined
          onClick={() => void handleCopyCodes()}
        />
        <Button
          type="button"
          label={t('2fa_done')}
          onClick={handleDone}
          raised
        />
      </div>
    </div>
  )

  const stepTitles: Record<WizardStep, string> = {
    setup: t('2fa_scan_qr'),
    verify: t('2fa_enter_code'),
    recovery: t('2fa_recovery_codes'),
  }

  return (
    <Dialog
      visible={visible}
      onHide={onClose}
      header={t('2fa_wizard_title')}
      style={{ width: '420px' }}
      modal
      draggable={false}
      resizable={false}
    >
      <div className="space-y-4">
        {/* Step indicator */}
        <div className="flex items-center gap-2 text-xs martis-text-muted mb-4">
          {(['setup', 'verify', 'recovery'] as WizardStep[]).map((s, i) => (
            <span key={s} className="flex items-center gap-2">
              {i > 0 && <span className="opacity-40">›</span>}
              <span className={step === s ? 'font-semibold martis-text' : ''}>
                {stepTitles[s]}
              </span>
            </span>
          ))}
        </div>

        {step === 'setup' && renderSetup()}
        {step === 'verify' && renderVerify()}
        {step === 'recovery' && renderRecovery()}
      </div>
    </Dialog>
  )
}
