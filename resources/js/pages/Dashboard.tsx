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
        <h1 className="text-2xl font-bold text-gray-900 dark:text-white">
          {t('hello', { name })}
        </h1>
        <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">
          {t('welcome')}
        </p>
      </div>

      {isLoading ? (
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          {[1, 2, 3].map((i) => <CardSkeleton key={i} />)}
        </div>
      ) : (
        <>
          {/* Stats row */}
          <div className="mb-6 grid gap-4 sm:grid-cols-3">
            <div className="rounded-xl bg-gradient-to-br from-indigo-600 to-indigo-700 p-5 text-white shadow-lg shadow-indigo-200 dark:shadow-none">
              <div className="flex items-center justify-between">
                <div>
                  <p className="text-sm font-medium text-indigo-100">{t('registered')}</p>
                  <p className="mt-1 text-3xl font-bold">{totalResources}</p>
                </div>
                <div className="flex h-12 w-12 items-center justify-center rounded-xl bg-white/20">
                  <i className="pi pi-database text-xl" />
                </div>
              </div>
            </div>
            <div className="rounded-xl bg-gradient-to-br from-emerald-500 to-emerald-600 p-5 text-white shadow-lg shadow-emerald-200 dark:shadow-none">
              <div className="flex items-center justify-between">
                <div>
                  <p className="text-sm font-medium text-emerald-100">Groups</p>
                  <p className="mt-1 text-3xl font-bold">{groups.length}</p>
                </div>
                <div className="flex h-12 w-12 items-center justify-center rounded-xl bg-white/20">
                  <i className="pi pi-folder text-xl" />
                </div>
              </div>
            </div>
            <div className="rounded-xl bg-gradient-to-br from-amber-500 to-orange-500 p-5 text-white shadow-lg shadow-amber-200 dark:shadow-none">
              <div className="flex items-center justify-between">
                <div>
                  <p className="text-sm font-medium text-amber-100">Active</p>
                  <p className="mt-1 text-3xl font-bold">{totalResources}</p>
                </div>
                <div className="flex h-12 w-12 items-center justify-center rounded-xl bg-white/20">
                  <i className="pi pi-check-circle text-xl" />
                </div>
              </div>
            </div>
          </div>

          {/* Resource cards */}
          <h2 className="mb-3 text-lg font-semibold text-gray-900 dark:text-white">{t('registered')}</h2>
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            {groups.flatMap((g) =>
              g.resources.map((r) => (
                <Link key={r.uriKey} to={`/resources/${r.uriKey}`} className="block">
                  <Card className="transition-all hover:shadow-md cursor-pointer">
                    <div className="flex items-center gap-4">
                      <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-indigo-50 dark:bg-indigo-900/30">
                        <i className="pi pi-database text-indigo-600 dark:text-indigo-400" />
                      </div>
                      <div>
                        <p className="font-semibold text-gray-900 dark:text-white">{r.label}</p>
                        {r.group && (
                          <p className="text-xs text-gray-500 dark:text-gray-400">{r.group}</p>
                        )}
                      </div>
                      <i className="pi pi-chevron-right ml-auto text-gray-400" />
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
