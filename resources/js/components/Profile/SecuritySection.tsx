import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Badge } from 'primereact/badge'
import { Dialog } from 'primereact/dialog'
import { ShieldCheck, ShieldSlash, Copy, Check, ArrowsClockwise, Warning, X, Trash } from '@phosphor-icons/react'
import { TwoFactorWizard } from './TwoFactorWizard'
import { api } from '@/lib/api'
import { useToast } from '@/contexts/ToastContext'

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
  const [recoveryOpen, setRecoveryOpen] = useState(false)
  const [recoveryCodes, setRecoveryCodes] = useState<string[]>([])
  const [regenerating, setRegenerating] = useState(false)
  const [copied, setCopied] = useState(false)

  async function handleDisable() {
    setDisabling(true)
    try {
      await api.delete('/api/profile/2fa')
      onUpdate(false)
      addToast('success', t('2fa_disabled_success'))
    } catch {
      addToast('error', t('error'))
    } finally {
      setDisabling(false)
      setDisableConfirmOpen(false)
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
            <ShieldCheck size={24} className="text-green-500" />
          ) : (
            <ShieldSlash size={24} className="martis-text-muted" />
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
                className="inline-flex items-center gap-2 rounded-lg border px-3 py-1.5 text-sm font-medium transition-colors hover:opacity-90 disabled:opacity-50"
                style={{
                  backgroundColor: 'var(--martis-surface-alt)',
                  borderColor: 'var(--martis-border)',
                  color: 'var(--martis-text)',
                }}
              >
                <ArrowsClockwise size={14} />
                {regenerating ? t('2fa_regenerating') : t('2fa_view_recovery')}
              </button>
              <button
                type="button"
                onClick={() => setDisableConfirmOpen(true)}
                className="inline-flex items-center gap-2 rounded-lg border px-3 py-1.5 text-sm font-medium transition-colors hover:opacity-90 disabled:opacity-50"
                style={{
                  backgroundColor: 'var(--martis-surface-alt)',
                  borderColor: '#dc2626',
                  color: '#dc2626',
                }}
              >
                <ShieldSlash size={14} />
                {t('2fa_disable')}
              </button>
            </>
          ) : (
            <button
              type="button"
              onClick={() => setWizardOpen(true)}
              className="inline-flex items-center gap-2 rounded-lg px-3 py-1.5 text-sm font-medium text-white transition-colors hover:opacity-90"
              style={{ backgroundColor: 'var(--martis-accent)' }}
            >
              <ShieldCheck size={14} />
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
      <Dialog
        visible={disableConfirmOpen}
        onHide={() => setDisableConfirmOpen(false)}
        header={
          <div className="flex items-center gap-3">
            <div className="flex h-9 w-9 items-center justify-center rounded-full bg-red-100 dark:bg-red-900/30">
              <Warning size={18} className="text-red-600 dark:text-red-400" weight="fill" />
            </div>
            <span>{t('2fa_disable_confirm_title')}</span>
          </div>
        }
        style={{ width: '420px' }}
        modal
        draggable={false}
        resizable={false}
        footer={
          <div className="flex justify-end gap-3 pt-2">
            <button
              type="button"
              disabled={disabling}
              onClick={() => setDisableConfirmOpen(false)}
              className="inline-flex items-center gap-2 rounded-lg border px-4 py-2 text-sm font-medium transition-colors hover:opacity-90 disabled:opacity-50"
              style={{
                backgroundColor: 'var(--martis-surface-alt)',
                borderColor: 'var(--martis-border)',
                color: 'var(--martis-text)',
              }}
            >
              <X size={14} />
              {t('2fa_cancel')}
            </button>
            <button
              type="button"
              disabled={disabling}
              onClick={() => void handleDisable()}
              className="inline-flex items-center gap-2 rounded-lg px-4 py-2 text-sm font-medium text-white transition-colors hover:opacity-90 disabled:opacity-50"
              style={{ backgroundColor: '#dc2626' }}
            >
              <Trash size={14} />
              {disabling ? t('saving') : t('2fa_disable_confirm')}
            </button>
          </div>
        }
      >
        <p className="text-sm" style={{ color: 'var(--martis-text-muted)' }}>
          {t('2fa_disable_confirm_body')}
        </p>
      </Dialog>

      {/* Recovery Codes Dialog */}
      <Dialog
        visible={recoveryOpen}
        onHide={() => setRecoveryOpen(false)}
        header={t('2fa_recovery_codes')}
        style={{ width: '420px' }}
        modal
        draggable={false}
        resizable={false}
      >
        <div className="space-y-4">
          <p className="text-sm martis-text-muted">{t('2fa_regen_warning')}</p>

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
            <button
              type="button"
              onClick={() => void handleCopyCodes()}
              className="inline-flex items-center gap-2 rounded-lg border px-4 py-2 text-sm font-medium transition-colors hover:opacity-90"
              style={{
                backgroundColor: 'var(--martis-surface-alt)',
                borderColor: 'var(--martis-border)',
                color: 'var(--martis-text)',
              }}
            >
              {copied ? <Check size={14} /> : <Copy size={14} />}
              {copied ? t('2fa_codes_copied') : t('2fa_copy_codes')}
            </button>
            <button
              type="button"
              onClick={() => setRecoveryOpen(false)}
              className="inline-flex items-center gap-2 rounded-lg px-4 py-2 text-sm font-medium text-white transition-colors hover:opacity-90"
              style={{ backgroundColor: 'var(--martis-accent)' }}
            >
              {t('2fa_done')}
            </button>
          </div>
        </div>
      </Dialog>
    </section>
  )
}
