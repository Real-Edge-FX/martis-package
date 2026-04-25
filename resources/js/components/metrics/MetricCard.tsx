import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { api } from '@/lib/api'
import { ArrowClockwiseIcon } from '@phosphor-icons/react'
import { useTranslation } from 'react-i18next'
import { ResourceIcon } from '@/components/ResourceIcon'
import type { MetricDefinition, ActiveFilters } from '@/types'
import { ValueCard } from './ValueCard'
import { TrendCard } from './TrendCard'
import { PartitionCard } from './PartitionCard'
import { ProgressCard } from './ProgressCard'
import { ActivityFeedCard } from './ActivityFeedCard'
import { EndpointTableCard } from './EndpointTableCard'

interface MetricCardProps {
  metric: MetricDefinition
  endpoint: string
  filters?: ActiveFilters
  /** Optional pre-rendered body to replace the default metric content (framed custom cards). */
  customContent?: React.ReactNode
}

export function MetricCard({ metric, endpoint, filters, customContent }: MetricCardProps) {
  const { t } = useTranslation('resources')
  const [range, setRange] = useState<string>(
    Object.keys(metric.ranges ?? {})[0] ?? '30',
  )

  const ranges = metric.ranges ?? {}
  const hasRanges = Object.keys(ranges).length > 0

  // Plain Card subclasses render their own content via `customContent` —
  // they don't expose a compute endpoint and hitting one returns 404.
  const isFetchableMetric = metric.type !== 'card'

  const query = useQuery({
    queryKey: ['metric', metric.uriKey, endpoint, range, filters],
    queryFn: () => {
      const params = new URLSearchParams({ range })
      if (filters && Object.keys(filters).length > 0) {
        params.set('filters', JSON.stringify(filters))
      }
      return api.get<{ data: { result: Record<string, unknown> } }>(
        `${endpoint}?${params.toString()}`,
      )
    },
    enabled: isFetchableMetric,
    refetchInterval: metric.refreshEvery ? metric.refreshEvery * 1000 : undefined,
  })

  const result = query.data?.data?.result ?? null
  const isLive = !!metric.refreshEvery

  // Compute responsive grid column
  const gridColumn = metric.width ? `span ${metric.width}` : 'span 4'

  // Card style accent colors (Martis extension)
  const styleColors: Record<string, string> = {
    success: 'var(--martis-success)',
    warning: 'var(--martis-warning)',
    danger: 'var(--martis-danger)',
    info: 'var(--martis-info)',
  }
  const cardStyle = metric.style ?? 'default'
  const accentColor = styleColors[cardStyle] ?? null

  return (
    <div
      className="martis-metric-card rounded-lg"
      style={{
        gridColumn,
        border: '1px solid var(--martis-border)',
        borderLeft: accentColor ? `4px solid ${accentColor}` : '1px solid var(--martis-border)',
        backgroundColor: 'var(--martis-surface)',
        minHeight: metric.height ? `${metric.height}px` : undefined,
      }}
    >
      {/* Card header */}
      <div
        className="martis-metric-card-head flex flex-wrap items-center justify-between gap-2 px-4 py-3"
        style={{ borderBottom: '1px solid var(--martis-border)' }}
      >
        <div className="flex items-center gap-2 min-w-0">
          <h3 className="martis-kpi-label min-w-0">
            {metric.icon && (
              <span className="martis-kpi-label-icon" style={{ color: accentColor ?? undefined }}>
                <ResourceIcon iconName={metric.icon} size={14} />
              </span>
            )}
            <span className="martis-kpi-label-text">{metric.name}</span>
          </h3>
          {isLive && (
            <span
              className="martis-status-dot"
              data-pr-tooltip={`${t('auto_refresh', 'Auto-refresh')}: ${metric.refreshEvery}s`}
              data-pr-position="top"
            >
              <span className="martis-status-pulse" />
              {t('live', 'Live')}
            </span>
          )}
        </div>

        <div className="flex items-center gap-2 flex-shrink-0">
          {hasRanges && (
            <select
              value={range}
              onChange={(e) => setRange(e.target.value)}
              className="text-xs rounded border px-1.5 py-1"
              style={{
                borderColor: 'var(--martis-border)',
                backgroundColor: 'var(--martis-input-bg)',
                color: 'var(--martis-text)',
              }}
            >
              {Object.entries(ranges).map(([value, label]) => (
                <option key={value} value={value}>
                  {label}
                </option>
              ))}
            </select>
          )}

          {query.isFetching && (
            <ArrowClockwiseIcon
              size={14}
              className="animate-spin"
              style={{ color: 'var(--martis-text-muted)' }}
            />
          )}
        </div>
      </div>

      {/* Card content */}
      <div className="martis-metric-card-body p-4">
        {customContent ? (
          customContent
        ) : result === null && query.isLoading ? (
          <span className="martis-skeleton" style={{ display: 'block', height: '5rem', width: '100%' }} aria-hidden="true" />
        ) : result ? (
          <MetricContent metricType={metric.metricType ?? 'value'} result={result} color={metric.color ?? null} />
        ) : (
          <p
            className="text-sm text-center py-4"
            style={{ color: 'var(--martis-text-muted)' }}
          >
            {t('no_data', 'No data available')}
          </p>
        )}
      </div>
    </div>
  )
}

function MetricContent({
  metricType,
  result,
  color,
}: {
  metricType: string
  result: Record<string, unknown>
  color?: string | null
}) {
  switch (metricType) {
    case 'value':
      return <ValueCard data={result} />
    case 'trend':
      return <TrendCard data={result} color={color} />
    case 'partition':
      return <PartitionCard data={result} />
    case 'progress':
      return <ProgressCard data={result} color={color} />
    case 'activity_feed':
      return <ActivityFeedCard data={result} />
    case 'endpoint_table':
      return <EndpointTableCard data={result} />
    default:
      return null
  }
}
