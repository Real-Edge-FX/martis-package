import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { House } from '@phosphor-icons/react'

export function NotFoundPage() {
  const { t } = useTranslation('messages')

  return (
    <div className="flex flex-col items-center justify-center py-20">
      <div className="text-6xl font-bold martis-text-muted mb-2">404</div>
      <p className="text-lg mb-6" style={{ color: 'var(--martis-text-muted)' }}>
        {t('page_not_found')}
      </p>
      <Link
        to="/"
        className="inline-flex items-center gap-2 rounded-lg px-4 py-2 text-sm font-medium text-white"
        style={{ backgroundColor: 'var(--martis-accent)' }}
      >
        <House size={16} weight="bold" />
        {t('back_to_dashboard')}
      </Link>
    </div>
  )
}
