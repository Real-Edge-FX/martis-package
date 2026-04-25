import { useState } from 'react'
import { createPortal } from 'react-dom'
import { useTranslation } from 'react-i18next'
import { Badge } from 'primereact/badge'
import { ShieldCheckIcon, ShieldSlashIcon, CopyIcon, CheckIcon, ArrowsClockwiseIcon, WarningIcon, XIcon, TrashIcon } from '@phosphor-icons/react'
import { TwoFactorWizard } from './TwoFactorWizard'
import { api, ApiError } from '@/lib/api'
import { useToast } from '@/contexts/ToastContext'
import { useModalHistoryLock } from '@/lib/historyLock'

interface SecuritySectionProps {
  twoFactorEnabled: boolean
  onUpdate: (enabled: boolean) => void
}

export function SecuritySection({ twoFactorEnabled, onUpdate }: SecuritySectionProps) {
  const { t } = useTranslation('profile')
  const { addToast } = useToast()
  const [wizardOpen, setWizardOpen] = useState(false)
  const [disabling, setDisabling] = useState(false)
  const [disableConfirmOpen, setDisableConfirmOpen] = useState(false)
  const [currentPassword, setCurrentPassword] = useState('')
  const [recoveryOpen, setRecoveryOpen] = useState(false)
  const [recoveryCodes, setRecoveryCodes] = useState<string[]>([])
  const [regenerating, setRegenerating] = useState(false)
  const [copied, setCopied] = useState(false)
  const [passwordError, setPasswordError] = useState('')

  useModalHistoryLock(disableConfirmOpen)
  useModalHistoryLock(recoveryOpen)

  function openDisableConfirm() {
    setCurrentPassword('')
    setPasswordError('')
    setDisableConfirmOpen(true)
  }

  function closeDisableConfirm() {
    setCurrentPassword('')
    setPasswordError('')
    setDisableConfirmOpen(false)
  }

  async function handleDisable() {
    setPasswordError('')
    setDisabling(true)
    try {
      await api.delete('/api/profile/2fa', { current_password: currentPassword })
      onUpdate(false)
      closeDisableConfirm()
      addToast('success', t('2fa_disabled_success'))
    } catch (err) {
      if (err instanceof ApiError && err.status === 422) {
        setPasswordError(t('current_password_wrong', { defaultValue: 'Password incorrecta. Tente novamente.' }))
      } else {
        addToast('error', t('error'))
      }
    } finally {
      setDisabling(false)
    }
  }

  async function handleViewRecoveryCodes() {
    setRegenerating(true)
    setRecoveryCodes([])
    try {
      const res = await api.post<{ recovery_codes: string[] }>('/api/profile/2fa/recovery-codes')
      setRecoveryCodes(res.recovery_codes ?? [])
      setRecoveryOpen(true)
      addToast('success', t('2fa_regen_success'))
    } catch {
      addToast('error', t('error'))
    } finally {
      setRegenerating(false)
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

  return (
    <section
      className="rounded-xl p-6 border martis-border martis-card-bg"
      aria-labelledby="security-section-title"
    >
      <h2 id="security-section-title" className="text-lg font-semibold martis-text mb-4">
        {t('security')}
      </h2>

      <div className="flex items-center justify-between gap-4 flex-wrap">
        <div className="flex items-center gap-3">
          {twoFactorEnabled ? (
            <ShieldCheckIcon size={24} className="text-green-500" />
          ) : (
            <ShieldSlashIcon size={24} className="martis-text-muted" />
          )}
          <div>
            <p className="text-sm font-medium martis-text">
              {t('security')}
            </p>
            <div className="mt-1">
              <Badge
                value={twoFactorEnabled ? t('2fa_enabled_badge') : t('2fa_disabled_badge')}
                severity={twoFactorEnabled ? 'success' : 'secondary'}
              />
            </div>
          </div>
        </div>

        <div className="flex items-center gap-2 flex-wrap">
          {twoFactorEnabled ? (
            <>
              <button
                type="button"
                disabled={regenerating}
                onClick={() => void handleViewRecoveryCodes()}
                className="martis-btn-secondary"
              >
                <ArrowsClockwiseIcon size={14} />
                {regenerating ? t('2fa_regenerating') : t('2fa_view_recovery')}
              </button>
              <button
                type="button"
                onClick={openDisableConfirm}
                className="martis-btn-danger"
              >
                <ShieldSlashIcon size={14} />
                {t('2fa_disable')}
              </button>
            </>
          ) : (
            <button
              type="button"
              onClick={() => setWizardOpen(true)}
              className="martis-btn-primary"
            >
              <ShieldCheckIcon size={14} />
              {t('2fa_enable')}
            </button>
          )}
        </div>
      </div>

      <TwoFactorWizard
        visible={wizardOpen}
        onClose={() => setWizardOpen(false)}
        onEnabled={() => onUpdate(true)}
      />

      {/* Confirm Disable 2FA Dialog */}
      {disableConfirmOpen && createPortal((
        <div className="martis-modal-scrim" onClick={closeDisableConfirm}>
          <div
            role="dialog"
            aria-modal="true"
            className="martis-modal-surface"
            style={{ maxWidth: '420px' }}
            onClick={(e) => e.stopPropagation()}
          >
            <div className="martis-modal-head">
              <div className="flex items-center gap-3">
                <WarningIcon size={18} weight="fill" style={{ color: 'var(--martis-danger)' }} />
                <h3 className="martis-modal-head-title">{t('2fa_disable_confirm_title')}</h3>
              </div>
              <button
                type="button"
                onClick={closeDisableConfirm}
                className="martis-modal-close"
                aria-label={t('2fa_cancel')}
              >
                <XIcon size={16} />
              </button>
            </div>

            <div className="martis-modal-body space-y-4">
              <p>{t('2fa_disable_confirm_body')}</p>
              <div>
                <label
                  htmlFor="disable-2fa-password"
                  className="block text-sm font-medium martis-text mb-1"
                >
                  {t('current_password')}
                </label>
                <input
                  id="disable-2fa-password"
                  type="password"
                  value={currentPassword}
                  onChange={(e) => { setCurrentPassword(e.target.value); setPasswordError('') }}
                  onKeyDown={(e) => { if (e.key === 'Enter' && currentPassword) void handleDisable() }}
                  className="w-full rounded-lg border px-3 py-2 text-sm martis-text martis-card-bg focus:outline-none focus:ring-2"
                  style={{ borderColor: passwordError ? 'var(--martis-danger)' : 'var(--martis-border)' }}
                  autoComplete="current-password"
                />
                {passwordError && (
                  <p className="mt-1 text-xs" style={{ color: 'var(--martis-danger)' }}>{passwordError}</p>
                )}
              </div>
            </div>

            <div className="martis-modal-foot">
              <button
                type="button"
                disabled={disabling}
                onClick={closeDisableConfirm}
                className="martis-btn-secondary"
              >
                <XIcon size={14} />
                {t('2fa_cancel')}
              </button>
              <button
                type="button"
                disabled={disabling || !currentPassword}
                onClick={() => void handleDisable()}
                className="martis-btn-danger"
              >
                <TrashIcon size={14} />
                {disabling ? t('saving') : t('2fa_disable_confirm')}
              </button>
            </div>
          </div>
        </div>
      ), document.body)}

      {/* Recovery Codes Dialog */}
      {recoveryOpen && createPortal((
        <div className="martis-modal-scrim" onClick={() => setRecoveryOpen(false)}>
          <div
            role="dialog"
            aria-modal="true"
            className="martis-modal-surface"
            style={{ maxWidth: '420px' }}
            onClick={(e) => e.stopPropagation()}
          >
            <div className="martis-modal-head">
              <h3 className="martis-modal-head-title">{t('2fa_recovery_codes')}</h3>
              <button
                type="button"
                onClick={() => setRecoveryOpen(false)}
                className="martis-modal-close"
                aria-label={t('2fa_cancel')}
              >
                <XIcon size={16} />
              </button>
            </div>

            <div className="martis-modal-body space-y-4">
              <p>{t('2fa_regen_warning')}</p>

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
            </div>

            <div className="martis-modal-foot">
              <button
                type="button"
                onClick={() => void handleCopyCodes()}
                className="martis-btn-secondary"
              >
                {copied ? <CheckIcon size={14} /> : <CopyIcon size={14} />}
                {copied ? t('2fa_codes_copied') : t('2fa_copy_codes')}
              </button>
              <button
                type="button"
                onClick={() => setRecoveryOpen(false)}
                className="martis-btn-primary"
              >
                {t('2fa_done')}
              </button>
            </div>
          </div>
        </div>
      ), document.body)}
    </section>
  )
}
