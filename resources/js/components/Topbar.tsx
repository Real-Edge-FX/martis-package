import { useAuth } from '@/contexts/AuthContext'
import { useTheme } from '@/contexts/ThemeContext'
import { useToast } from '@/contexts/ToastContext'
import { Breadcrumbs } from '@/components/Breadcrumbs'
import { Sun, Moon, SignOut } from '@phosphor-icons/react'
import { Button } from 'primereact/button'
import { useTranslation } from 'react-i18next'

export function Topbar() {
  const { user, logout } = useAuth()
  const { theme, toggle } = useTheme()
  const { addToast } = useToast()
  const { t } = useTranslation('navigation')
  const { t: tAuth } = useTranslation('auth')

  async function handleLogout() {
    await logout()
    addToast('success', tAuth('session_ended'))
  }

  return (
    <header className="flex h-14 items-center justify-between border-b border-gray-200 bg-white px-4 dark:border-gray-800 dark:bg-gray-900">
      <Breadcrumbs />

      <div className="flex items-center gap-2">
        <Button
          onClick={toggle}
          aria-label={t('toggle_theme')}
          className="p-button-text p-button-secondary p-button-sm"
          icon={
            theme === 'dark'
              ? <Sun size={18} className="text-gray-500 dark:text-gray-400" />
              : <Moon size={18} className="text-gray-500 dark:text-gray-400" />
          }
        />

        <span className="text-sm text-gray-600 dark:text-gray-400">{user?.name ?? user?.email}</span>

        <Button
          onClick={() => void handleLogout()}
          aria-label={t('logout')}
          className="p-button-text p-button-secondary p-button-sm"
          icon={<SignOut size={18} className="text-gray-500 dark:text-gray-400" />}
        />
      </div>
    </header>
  )
}
