import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Button } from 'primereact/button'
import { Badge } from 'primereact/badge'
import { Dialog } from 'primereact/dialog'
import { ShieldCheck, ShieldSlash, Copy, Check, ArrowsClockwise } from '@phosphor-icons/react'
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
              <Button
                type="button"
                label={regenerating ? t('2fa_regenerating') : t('2fa_view_recovery')}
                icon={<ArrowsClockwise size={16} />}
                outlined
                loading={regenerating}
                onClick={() => void handleViewRecoveryCodes()}
                size="small"
              />
              <Button
                type="button"
                label={disabling ? t('saving') : t('2fa_disable')}
                severity="danger"
                outlined
                loading={disabling}
                onClick={() => void handleDisable()}
                size="small"
              />
            </>
          ) : (
            <Button
              type="button"
              label={t('2fa_enable')}
              icon={<ShieldCheck size={16} />}
              raised
              onClick={() => setWizardOpen(true)}
              size="small"
            />
          )}
        </div>
      </div>

      <TwoFactorWizard
        visible={wizardOpen}
        onClose={() => setWizardOpen(false)}
        onEnabled={() => onUpdate(true)}
      />

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
              onClick={() => setRecoveryOpen(false)}
              raised
            />
          </div>
        </div>
      </Dialog>
    </section>
  )
}
