import { NavLink } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { api } from '@/lib/api'
import type { NavigationGroup } from '@/types'
import { useTranslation } from 'react-i18next'

function getBrand(): string {
  return window.MartisConfig?.brand ?? 'Martis'
}

function navClass({ isActive }: { isActive: boolean }) {
  return [
    'flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium transition-all',
    isActive
      ? 'bg-white/15 text-white shadow-sm'
      : 'text-indigo-100 hover:bg-white/10 hover:text-white',
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
    <aside className="flex h-full w-60 flex-col bg-gradient-to-b from-indigo-700 via-indigo-600 to-purple-700 px-3 py-5">
      <div className="mb-8 flex items-center gap-3 px-3">
        <div className="flex h-9 w-9 items-center justify-center rounded-xl bg-white/20">
          <i className="pi pi-shield text-lg text-white" />
        </div>
        <span className="text-lg font-bold text-white">{getBrand()}</span>
      </div>

      <nav className="flex-1 space-y-1">
        <NavLink to="/" end className={navClass}>
          <i className="pi pi-th-large text-sm" />
          {t('dashboard')}
        </NavLink>

        {groups.map((group, i) => (
          <div key={group.label ?? i} className="pt-5">
            {group.label && (
              <p className="mb-2 px-3 text-[11px] font-semibold uppercase tracking-widest text-indigo-200/60">
                {group.label}
              </p>
            )}
            {group.resources.map((r) => (
              <NavLink key={r.uriKey} to={`/resources/${r.uriKey}`} className={navClass}>
                <i className="pi pi-database text-sm" />
                {r.label}
              </NavLink>
            ))}
          </div>
        ))}
      </nav>

      <div className="mt-auto px-3 pt-4 border-t border-white/10">
        <p className="text-[11px] text-indigo-200/50 text-center">{t('footer')}</p>
      </div>
    </aside>
  )
}
