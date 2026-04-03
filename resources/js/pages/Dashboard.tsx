import { useQuery } from '@tanstack/react-query'
import { api } from '@/lib/api'
import type { NavigationGroup } from '@/types'
import { useAuth } from '@/contexts/AuthContext'
import { CardSkeleton } from '@/components/LoadingSkeleton'
import { Card } from 'primereact/card'
import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'

export function DashboardPage() {
  const { user } = useAuth()
  const { t } = useTranslation('resources')
  const { data: groups = [], isLoading } = useQuery<NavigationGroup[]>({
    queryKey: ['navigation'],
    queryFn: () => api.get('/api/navigation'),
    staleTime: 1000 * 60,
  })

  const totalResources = groups.reduce((n, g) => n + g.resources.length, 0)
  const name = user?.name ?? user?.email ?? ''

  return (
    <div>
      <div className="mb-6">
        <h1 className="text-2xl font-bold text-white">
          {t('hello', { name })}
        </h1>
        <p className="mt-1 text-sm text-slate-400">
          {t('welcome')}
        </p>
      </div>

      {isLoading ? (
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          {[1, 2, 3].map((i) => <CardSkeleton key={i} />)}
        </div>
      ) : (
        <>
          {/* Stats row — Nova-style dark metric cards */}
          <div className="mb-6 grid gap-4 sm:grid-cols-3">
            <div className="rounded-xl p-5" style={{ backgroundColor: '#1e293b', border: '1px solid #334155' }}>
              <div className="flex items-center justify-between">
                <div>
                  <p className="text-sm font-medium text-slate-400">{t('registered')}</p>
                  <p className="mt-1 text-3xl font-bold text-white">{totalResources}</p>
                </div>
                <div className="flex h-12 w-12 items-center justify-center rounded-xl bg-indigo-500/20">
                  <i className="pi pi-database text-xl text-indigo-400" />
                </div>
              </div>
            </div>
            <div className="rounded-xl p-5" style={{ backgroundColor: '#1e293b', border: '1px solid #334155' }}>
              <div className="flex items-center justify-between">
                <div>
                  <p className="text-sm font-medium text-slate-400">Groups</p>
                  <p className="mt-1 text-3xl font-bold text-white">{groups.length}</p>
                </div>
                <div className="flex h-12 w-12 items-center justify-center rounded-xl bg-emerald-500/20">
                  <i className="pi pi-folder text-xl text-emerald-400" />
                </div>
              </div>
            </div>
            <div className="rounded-xl p-5" style={{ backgroundColor: '#1e293b', border: '1px solid #334155' }}>
              <div className="flex items-center justify-between">
                <div>
                  <p className="text-sm font-medium text-slate-400">Active</p>
                  <p className="mt-1 text-3xl font-bold text-white">{totalResources}</p>
                </div>
                <div className="flex h-12 w-12 items-center justify-center rounded-xl bg-amber-500/20">
                  <i className="pi pi-check-circle text-xl text-amber-400" />
                </div>
              </div>
            </div>
          </div>

          {/* Resource cards */}
          <h2 className="mb-3 text-lg font-semibold text-white">{t('registered')}</h2>
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            {groups.flatMap((g) =>
              g.resources.map((r) => (
                <Link key={r.uriKey} to={`/resources/${r.uriKey}`} className="block">
                  <Card className="transition-all hover:shadow-md cursor-pointer">
                    <div className="flex items-center gap-4">
                      <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-indigo-500/20">
                        <i className="pi pi-database text-indigo-400" />
                      </div>
                      <div>
                        <p className="font-semibold text-white">{r.label}</p>
                        {r.group && (
                          <p className="text-xs text-slate-400">{r.group}</p>
                        )}
                      </div>
                      <i className="pi pi-chevron-right ml-auto text-slate-500" />
                    </div>
                  </Card>
                </Link>
              )),
            )}
          </div>
        </>
      )}
    </div>
  )
}
