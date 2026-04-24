import { useTranslation } from 'react-i18next'
import { LockIcon } from '@phosphor-icons/react'
import { ErrorScreen } from '@/components/auth/ErrorScreen'

export function ForbiddenPage() {
  const { t } = useTranslation('messages')

  return (
    <ErrorScreen
      code="403"
      icon={<LockIcon size={32} weight="regular" />}
      title={t('forbidden_title', { defaultValue: 'Forbidden' })}
      description={t('forbidden_desc', {
        defaultValue: "Your account doesn't have permission to access this resource. Ask a workspace admin to grant the role you need.",
      })}
      primaryLabel={t('error_back_to_dashboard', { defaultValue: 'Back to dashboard' })}
    />
  )
}
