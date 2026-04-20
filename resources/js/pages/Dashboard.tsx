import { useState } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { api } from '@/lib/api'
import { config } from '@/lib/config'
import { getNavigationResourceItems } from '@/lib/navigation'
import type { NavigationGroup, DashboardDefinition, DashboardData, ActiveFilters } from '@/types'
import { useAuth } from '@/contexts/AuthContext'
import { CardSkeleton } from '@/components/LoadingSkeleton'
import { ResourceIcon } from '@/components/ResourceIcon'
import { MetricCard } from '@/components/metrics'
import { componentRegistry } from '@/lib/componentRegistry'
import { FilterPanel } from '@/components/FilterPanel'
import { Card } from 'primereact/card'
import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { DatabaseIcon, FolderIcon, CheckCircleIcon, CaretRightIcon, ArrowClockwiseIcon } from '@phosphor-icons/react'
import { usePageTitle } from '@/hooks/usePageTitle'

export function DashboardPage() {
  const { user } = useAuth()
  const { t } = useTranslation('resources')

  // Fetch navigation (needed for default layout + fallback)
  const { data: groups = [], isLoading: navLoading } = useQuery<NavigationGroup[]>({
    queryKey: ['navigation'],
    queryFn: () => api.get('/api/navigation'),
    staleTime: 1000 * 60,
  })

  // Fetch registered dashboards
  const dashboardsQuery = useQuery({
    queryKey: ['dashboards'],
    queryFn: () => api.get<{ data: { dashboards: DashboardDefinition[] } }>('/api/dashboards'),
  })

  const dashboards = dashboardsQuery.data?.data?.dashboards ?? []
  const hasDashboards = dashboards.length > 0

  const [activeDashboard, setActiveDashboard] = useState<string | null>(null)

  // Use first dashboard as default
  const currentDashboardKey = activeDashboard ?? dashboards[0]?.uriKey ?? null

  const name = user?.name ?? user?.email ?? ''
  const showGreeting = config.dashboard?.showGreeting !== false
  const showWelcome = config.dashboard?.showWelcome !== false

  return (
    <div>
      {(showGreeting || showWelcome) && (
        <div className="mb-6">
          {showGreeting && (
            <h1 className="text-2xl font-bold" style={{ color: 'var(--martis-text)' }}>
              {t('hello', { name })}
            </h1>
          )}
          {showWelcome && (
            <p className={`${showGreeting ? 'mt-1 ' : ''}text-sm`} style={{ color: 'var(--martis-text-muted)' }}>
              {t('welcome')}
            </p>
          )}
        </div>
      )}

      {navLoading || dashboardsQuery.isLoading ? (
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          {[1, 2, 3].map((i) => <CardSkeleton key={i} />)}
        </div>
      ) : hasDashboards ? (
        <DashboardView
          dashboards={dashboards}
          currentKey={currentDashboardKey}
          onSelect={setActiveDashboard}
          groups={groups}
        />
      ) : (
        <DefaultDashboardView groups={groups} />
      )}
    </div>
  )
}

// ---------------------------------------------------------------------------
// Dynamic Dashboard View — renders metrics from registered dashboards
// ---------------------------------------------------------------------------

function DashboardView({
  dashboards,
  currentKey,
  onSelect,
  groups,
}: {
  dashboards: DashboardDefinition[]
  currentKey: string | null
  onSelect: (key: string) => void
  groups: NavigationGroup[]
}) {
  const { t } = useTranslation('resources')
  const qc = useQueryClient()
  const [activeFilters, setActiveFilters] = useState<ActiveFilters>({})

  const currentDashboard = dashboards.find((d) => d.uriKey === currentKey) ?? null
  const isDefaultLayout = currentDashboard?.layout === 'default'

  usePageTitle(currentDashboard?.name ?? null)

  // Fetch dashboard data (cards + filters) — skip for default layout (no cards)
  const dashboardQuery = useQuery({
    queryKey: ['dashboard', currentKey],
    queryFn: () => api.get<{ data: DashboardData }>(`/api/dashboards/${currentKey}`),
    enabled: !!currentKey && !isDefaultLayout,
  })

  const dashboardData = dashboardQuery.data?.data
  const cards = dashboardData?.cards ?? []
  const filters = dashboardData?.filters ?? []
  const showRefresh = dashboardData?.dashboard?.showRefreshButton ?? false

  const handleRefresh = () => {
    void qc.invalidateQueries({ queryKey: ['metric'] })
  }

  return (
    <div className="space-y-4">
      {/* Dashboard tabs (when multiple dashboards exist) */}
      {dashboards.length > 1 && (
        <div className="flex gap-1 overflow-x-auto pb-1">
          {dashboards.map((d) => (
            <button
              key={d.uriKey}
              type="button"
              onClick={() => { onSelect(d.uriKey); setActiveFilters({}) }}
              className="px-4 py-2 text-sm font-medium rounded-md whitespace-nowrap transition-colors"
              style={{
                backgroundColor: d.uriKey === currentKey ? 'var(--martis-accent)' : 'transparent',
                color: d.uriKey === currentKey ? '#fff' : 'var(--martis-text-muted)',
                border: d.uriKey === currentKey ? '1px solid var(--martis-accent)' : '1px solid var(--martis-border)',
              }}
            >
              {d.name}
            </button>
          ))}
        </div>
      )}

      {isDefaultLayout ? (
        <DefaultDashboardView groups={groups} />
      ) : (
        <>
          {/* Refresh + Filters — FilterPanel is standalone block like in resource index */}
          {filters.length > 0 ? (
            <FilterPanel
              filters={filters}
              value={activeFilters}
              onChange={(f) => setActiveFilters(f)}
              prefix={showRefresh ? (
                <button
                  type="button"
                  onClick={handleRefresh}
                  className="inline-flex items-center gap-2 px-3 py-1.5 text-sm font-medium transition-colors"
                  style={{ color: 'var(--martis-text-muted)', border: 'none', background: 'transparent', cursor: 'pointer' }}
                >
                  <ArrowClockwiseIcon size={14} />
                  {t('refresh', 'Refresh')}
                </button>
              ) : undefined}
            />
          ) : showRefresh ? (
            <button
              type="button"
              onClick={handleRefresh}
              className="inline-flex items-center gap-2 px-3 py-1.5 text-sm font-medium transition-colors"
              style={{ color: 'var(--martis-text-muted)', border: 'none', background: 'transparent', cursor: 'pointer' }}
            >
              <ArrowClockwiseIcon size={14} />
              {t('refresh', 'Refresh')}
            </button>
          ) : null}

          {/* Metric cards grid (12-column) */}
          {dashboardQuery.isLoading ? (
            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
              {[1, 2, 3].map((i) => <CardSkeleton key={i} />)}
            </div>
          ) : cards.length > 0 ? (
            <div
              className="grid gap-4"
              style={{ gridTemplateColumns: 'repeat(12, minmax(0, 1fr))' }}
            >
              {cards.map((card) => {
                if (card.component) {
                  const CustomCard = componentRegistry.resolve(card.component)
                  if (CustomCard) {
                    const C = CustomCard as React.ComponentType<{ card: typeof card }>
                    const span = card.width ?? 4
                    if (card.framed) {
                      return (
                        <MetricCard
                          key={card.uriKey}
                          metric={card}
                          endpoint={`/api/dashboards/${currentKey}/cards/${card.uriKey}`}
                          filters={activeFilters}
                          customContent={<C card={card} />}
                        />
                      )
                    }
                    return (
                      <div key={card.uriKey} style={{ gridColumn: `span ${span} / span ${span}` }}>
                        <C card={card} />
                      </div>
                    )
                  }
                }
                return (
                  <MetricCard
                    key={card.uriKey}
                    metric={card}
                    endpoint={`/api/dashboards/${currentKey}/cards/${card.uriKey}`}
                    filters={activeFilters}
                  />
                )
              })}
            </div>
          ) : (
            <p className="text-sm py-8 text-center" style={{ color: 'var(--martis-text-muted)' }}>
              {t('no_data', 'No cards configured for this dashboard.')}
            </p>
          )}
        </>
      )}
    </div>
  )
}

// ---------------------------------------------------------------------------
// Default Martis dashboard — navigation-derived summary + resource cards.
// Shared between: (a) DefaultDashboard registered by the app, and
// (b) the automatic fallback shown when no dashboards are registered.
// ---------------------------------------------------------------------------

function DefaultDashboardView({ groups }: { groups: NavigationGroup[] }) {
  const { t } = useTranslation('resources')

  const navigationResources = groups.flatMap((group) => getNavigationResourceItems(group))
  const totalResources = navigationResources.length

  const showMetrics = config.dashboard?.showMetrics !== false
  const showResourceCards = config.dashboard?.showResourceCards !== false

  return (
    <>
      {showMetrics && (
        <div className="mb-6 grid gap-4 sm:grid-cols-3">
          <StatCard label={t('registered')} value={totalResources} icon={<DatabaseIcon size={20} className="text-indigo-400" />} bgClass="bg-indigo-500/20" />
          <StatCard label={t('groups')} value={groups.length} icon={<FolderIcon size={20} className="text-emerald-400" />} bgClass="bg-emerald-500/20" />
          <StatCard label={t('active')} value={totalResources} icon={<CheckCircleIcon size={20} className="text-amber-400" />} bgClass="bg-amber-500/20" />
        </div>
      )}

      {showResourceCards && (
        <>
          <h2 className="mb-3 text-lg font-semibold" style={{ color: 'var(--martis-text)' }}>{t('registered')}</h2>
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            {navigationResources.map((r) => (
              <Link key={r.uriKey} to={`/resources/${r.uriKey}`} className="block h-full">
                <Card className="transition-all hover:shadow-md cursor-pointer h-full">
                  <div className="flex items-center gap-4 min-h-[2.5rem]">
                    <div className="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-lg bg-indigo-500/20">
                      <ResourceIcon iconName={r.icon ?? null} size={20} className="text-indigo-400" />
                    </div>
                    <div className="flex-1 min-w-0">
                      <p className="font-semibold" style={{ color: 'var(--martis-text)' }}>{r.label}</p>
                      {r.subtitle ? (
                        <p className="text-xs truncate" style={{ color: 'var(--martis-text-muted)' }}>{r.subtitle}</p>
                      ) : r.group ? (
                        <p className="text-xs truncate" style={{ color: 'var(--martis-text-muted)' }}>{r.group}</p>
                      ) : null}
                    </div>
                    <CaretRightIcon style={{ color: 'var(--martis-text-muted)' }} />
                  </div>
                </Card>
              </Link>
            ))}
          </div>
        </>
      )}
    </>
  )
}

// ---------------------------------------------------------------------------
// Static stat card (default dashboard)
// ---------------------------------------------------------------------------

function StatCard({ label, value, icon, bgClass }: { label: string; value: number; icon: React.ReactNode; bgClass: string }) {
  return (
    <div className="rounded-xl p-5" style={{ border: '1px solid var(--martis-border)', backgroundColor: 'var(--martis-surface)' }}>
      <div className="flex items-center justify-between">
        <div>
          <p className="text-sm font-medium" style={{ color: 'var(--martis-text-muted)' }}>{label}</p>
          <p className="mt-1 text-3xl font-bold" style={{ color: 'var(--martis-text)' }}>{value}</p>
        </div>
        <div className={`flex h-12 w-12 items-center justify-center rounded-xl ${bgClass}`}>
          {icon}
        </div>
      </div>
    </div>
  )
}
