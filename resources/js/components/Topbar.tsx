import { useAuth } from '@/contexts/AuthContext'
import { useTheme } from '@/contexts/ThemeContext'
import { useToast } from '@/contexts/ToastContext'
import { Breadcrumbs } from '@/components/Breadcrumbs'
import { Sun, Moon, LogOut } from 'lucide-react'
import { useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'

export function Topbar() {
  const { user, logout } = useAuth()
  const { theme, toggle } = useTheme()
  const { addToast } = useToast()
  const navigate = useNavigate()
  const { t } = useTranslation('navigation')
  const { t: tAuth } = useTranslation('auth')

  async function handleLogout() {
    await logout()
    addToast('success', tAuth('session_ended'))
    void navigate('/login')
  }

  return (
    <header className="flex h-14 items-center justify-between border-b border-gray-200 bg-white px-4 dark:border-gray-800 dark:bg-gray-900">
      <Breadcrumbs />

      <div className="flex items-center gap-3">
        <button
          onClick={toggle}
          aria-label={t('toggle_theme')}
          className="rounded-md p-2 text-gray-500 hover:bg-gray-100 dark:text-gray-400 dark:hover:bg-gray-800"
        >
          {theme === 'dark' ? <Sun size={18} /> : <Moon size={18} />}
        </button>

        <span className="text-sm text-gray-600 dark:text-gray-400">{user?.name ?? user?.email}</span>

        <button
          onClick={() => void handleLogout()}
          aria-label={t('logout')}
          className="rounded-md p-2 text-gray-500 hover:bg-gray-100 dark:text-gray-400 dark:hover:bg-gray-800"
        >
          <LogOut size={18} />
        </button>
      </div>
    </header>
  )
}
