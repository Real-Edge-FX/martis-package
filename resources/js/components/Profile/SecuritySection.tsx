import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Button } from 'primereact/button'
import { Badge } from 'primereact/badge'
import { ShieldCheck, ShieldSlash } from '@phosphor-icons/react'
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

        <div className="flex items-center gap-2">
          {twoFactorEnabled ? (
            <Button
              type="button"
              label={disabling ? t('saving') : t('2fa_disable')}
              severity="danger"
              outlined
              loading={disabling}
              onClick={() => void handleDisable()}
              size="small"
            />
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
    </section>
  )
}
