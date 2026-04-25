import { useMemo } from 'react'
import { useTranslation } from 'react-i18next'
import { PlugsIcon } from '@phosphor-icons/react'
import { ErrorScreen } from '@/components/auth/ErrorScreen'

interface ServerErrorPageProps {
  /** Optional incident id — when omitted a random `inc_*` placeholder is rendered. */
  incidentId?: string | null
}

function generatePlaceholderIncidentId(): string {
  const hex = Math.random().toString(16).slice(2, 10)
  return `inc_${hex.slice(0, 4)}_${hex.slice(4, 8)}`
}

export function ServerErrorPage({ incidentId }: ServerErrorPageProps = {}) {
  const { t } = useTranslation('messages')
  const resolvedIncidentId = useMemo(
    () => incidentId ?? generatePlaceholderIncidentId(),
    [incidentId],
  )

  return (
    <ErrorScreen
      code="500"
      icon={<PlugsIcon size={32} weight="regular" />}
      title={t('server_error_title', { defaultValue: 'Something went wrong' })}
      description={t('server_error_desc', {
        defaultValue: 'An unexpected error occurred on our end. The team has been notified and is investigating.',
      })}
      primaryLabel={t('error_try_again', { defaultValue: 'Try again' })}
      onPrimary={() => window.location.reload()}
      incidentId={resolvedIncidentId}
    />
  )
}
