import { useTranslation } from 'react-i18next'
import { CompassIcon } from '@phosphor-icons/react'
import { ErrorScreen } from '@/components/auth/ErrorScreen'

export function NotFoundPage() {
  const { t } = useTranslation('messages')

  return (
    <ErrorScreen
      code="404"
      icon={<CompassIcon size={32} weight="regular" />}
      title={t('not_found_title', { defaultValue: 'Resource not found' })}
      description={t('not_found_desc', {
        defaultValue: "The page you're looking for doesn't exist or you don't have permission to see it.",
      })}
    />
  )
}
