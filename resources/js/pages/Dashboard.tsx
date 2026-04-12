import { useQuery } from '@tanstack/react-query'
import { api } from '@/lib/api'
import { config } from '@/lib/config'
import type { NavigationGroup } from '@/types'
import { useAuth } from '@/contexts/AuthContext'
import { CardSkeleton } from '@/components/LoadingSkeleton'
import { ResourceIcon } from '@/components/ResourceIcon'
import { Card } from 'primereact/card'
import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { Database, Folder, CheckCircle, CaretRight } from '@phosphor-icons/react'

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

  const showMetrics = config.dashboard?.showMetrics !== false
  const showResourceCards = config.dashboard?.showResourceCards !== false

  return (
    <div>
      <div className="mb-6">
        <h1 className="text-2xl font-bold martis-text">
          {t('hello', { name })}
        </h1>
        <p className="mt-1 text-sm martis-text-muted">
          {t('welcome')}
        </p>
      </div>

      {isLoading ? (
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          {[1, 2, 3].map((i) => <CardSkeleton key={i} />)}
        </div>
      ) : (
        <>
          {/* Stats row — Nova-style metric cards */}
          {showMetrics && (
            <div className="mb-6 grid gap-4 sm:grid-cols-3">
              <div className="martis-card-bg rounded-xl p-5 border martis-border">
                <div className="flex items-center justify-between">
                  <div>
                    <p className="text-sm font-medium martis-text-muted">{t('registered')}</p>
                    <p className="mt-1 text-3xl font-bold martis-text">{totalResources}</p>
                  </div>
                  <div className="flex h-12 w-12 items-center justify-center rounded-xl bg-indigo-500/20">
                    <Database size={20} className="text-indigo-400" />
                  </div>
                </div>
              </div>
              <div className="martis-card-bg rounded-xl p-5 border martis-border">
                <div className="flex items-center justify-between">
                  <div>
                    <p className="text-sm font-medium martis-text-muted">{t('groups')}</p>
                    <p className="mt-1 text-3xl font-bold martis-text">{groups.length}</p>
                  </div>
                  <div className="flex h-12 w-12 items-center justify-center rounded-xl bg-emerald-500/20">
                    <Folder size={20} className="text-emerald-400" />
                  </div>
                </div>
              </div>
              <div className="martis-card-bg rounded-xl p-5 border martis-border">
                <div className="flex items-center justify-between">
                  <div>
                    <p className="text-sm font-medium martis-text-muted">{t('active')}</p>
                    <p className="mt-1 text-3xl font-bold martis-text">{totalResources}</p>
                  </div>
                  <div className="flex h-12 w-12 items-center justify-center rounded-xl bg-amber-500/20">
                    <CheckCircle size={20} className="text-amber-400" />
                  </div>
                </div>
              </div>
            </div>
          )}

          {/* Resource cards */}
          {showResourceCards && (
            <>
              <h2 className="mb-3 text-lg font-semibold martis-text">{t('registered')}</h2>
              <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                {groups.flatMap((g) =>
                  g.resources.map((r) => (
                    <Link key={r.uriKey} to={`/resources/${r.uriKey}`} className="block h-full">
                      <Card className="transition-all hover:shadow-md cursor-pointer h-full">
                        <div className="flex items-center gap-4 min-h-[2.5rem]">
                          <div className="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-lg bg-indigo-500/20">
                            <ResourceIcon iconName={r.icon ?? null} size={20} className="text-indigo-400" />
                          </div>
                          <div className="flex-1 min-w-0">
                            <p className="font-semibold martis-text">{r.label}</p>
                            {r.subtitle ? (
                              <p className="text-xs martis-text-muted truncate">{r.subtitle}</p>
                            ) : r.group ? (
                              <p className="text-xs martis-text-muted truncate">{r.group}</p>
                            ) : (
                              <p className="text-xs martis-text-muted invisible">–</p>
                            )}
                          </div>
                          <CaretRight className="flex-shrink-0 martis-text-muted" />
                        </div>
                      </Card>
                    </Link>
                  )),
                )}
              </div>
            </>
          )}
        </>
      )}
    </div>
  )
}
