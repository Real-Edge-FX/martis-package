import { NavLink } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { api } from '@/lib/api'
import type { NavigationGroup } from '@/types'
import { Database, SquaresFour } from '@phosphor-icons/react'
import { useTranslation } from 'react-i18next'

function navClass({ isActive }: { isActive: boolean }) {
  return [
    'flex items-center gap-2 rounded-md px-3 py-2 text-sm transition-colors',
    isActive
      ? 'bg-brand text-white'
      : 'text-gray-700 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800',
  ].join(' ')
}

export function Sidebar() {
  const { t } = useTranslation('navigation')
  const { data: groups = [] } = useQuery<NavigationGroup[]>({
    queryKey: ['navigation'],
    queryFn: () => api.get('/api/navigation'),
    staleTime: 1000 * 60,
  })

  return (
    <aside className="flex h-full w-60 flex-col border-r border-gray-200 bg-white px-3 py-4 dark:border-gray-800 dark:bg-gray-900">
      <div className="mb-6 px-3">
        <span className="text-lg font-bold text-brand">Martis</span>
      </div>

      <nav className="flex-1 space-y-1">
        <NavLink to="/martis" end className={navClass}>
          <SquaresFour size={16} weight="fill" />
          {t('dashboard')}
        </NavLink>

        {groups.map((group, i) => (
          <div key={group.label ?? i} className="pt-4">
            {group.label && (
              <p className="mb-1 px-3 text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-500">
                {group.label}
              </p>
            )}
            {group.resources.map((r) => (
              <NavLink key={r.uriKey} to={`/martis/resources/${r.uriKey}`} className={navClass}>
                <Database size={16} weight="fill" />
                {r.label}
              </NavLink>
            ))}
          </div>
        ))}
      </nav>

      <div className="mt-auto text-center">
        <span className="text-xs text-gray-400">{t('footer')}</span>
      </div>
    </aside>
  )
}
