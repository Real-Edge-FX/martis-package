import { useAuth } from '@/contexts/AuthContext'
import { useTheme } from '@/contexts/ThemeContext'
import { Breadcrumbs } from '@/components/Breadcrumbs'
import { Button } from 'primereact/button'
import { useTranslation } from 'react-i18next'

export function Topbar() {
  const { user, logout } = useAuth()
  const { theme, toggle } = useTheme()
  const { t } = useTranslation('navigation')

  return (
    <header className="flex h-14 items-center justify-between border-b border-gray-200 bg-white px-5 dark:border-gray-800 dark:bg-gray-900">
      <Breadcrumbs />

      <div className="flex items-center gap-2">
        <Button
          icon={`pi pi-${theme === 'dark' ? 'sun' : 'moon'}`}
          onClick={toggle}
          aria-label={t('toggle_theme')}
          rounded
          text
          severity="secondary"
          size="small"
        />

        <div className="flex items-center gap-2 rounded-lg bg-gray-50 px-3 py-1.5 dark:bg-gray-800">
          <div className="flex h-7 w-7 items-center justify-center rounded-full bg-indigo-600 text-xs font-bold text-white">
            {(user?.name ?? user?.email ?? '?')[0].toUpperCase()}
          </div>
          <span className="text-sm font-medium text-gray-700 dark:text-gray-300">
            {user?.name ?? user?.email}
          </span>
        </div>

        <Button
          icon="pi pi-sign-out"
          onClick={() => void logout()}
          aria-label={t('logout')}
          rounded
          text
          severity="danger"
          size="small"
        />
      </div>
    </header>
  )
}
