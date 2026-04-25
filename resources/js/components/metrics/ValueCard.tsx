import { ArrowUpRightIcon, ArrowDownRightIcon, MinusIcon } from '@phosphor-icons/react'
import { useTranslation } from 'react-i18next'

interface ValueCardProps {
  data: Record<string, unknown>
}

export function ValueCard({ data }: ValueCardProps) {
  const { t } = useTranslation('resources')
  const value = data.value as number ?? 0
  const previous = data.previous as number | undefined
  const change = data.change as number | undefined
  const prefix = data.prefix as string | undefined
  const suffix = data.suffix as string | undefined

  const formattedValue = `${prefix ?? ''}${value.toLocaleString()}${suffix ?? ''}`
  const hasDelta = previous !== undefined && value !== previous
  const trend = (change ?? 0) > 0 ? 'up' : (change ?? 0) < 0 ? 'down' : 'flat'
  const deltaClass =
    trend === 'up' ? 'martis-kpi-delta is-up'
    : trend === 'down' ? 'martis-kpi-delta is-down'
    : 'martis-kpi-delta'

  return (
    <div>
      <p className="martis-kpi-value">{formattedValue}</p>

      {hasDelta && (
        <div className="mt-2 flex items-center gap-2">
          <span className={deltaClass}>
            {trend === 'up'
              ? <ArrowUpRightIcon size={12} weight="bold" />
              : trend === 'down'
                ? <ArrowDownRightIcon size={12} weight="bold" />
                : <MinusIcon size={12} weight="bold" />}
            {change !== undefined && <>{change > 0 ? '+' : ''}{change}%</>}
            {previous !== undefined && (
              <span className="martis-kpi-delta-sub">
                {t('vs', 'vs')} {prefix ?? ''}{previous.toLocaleString()}{suffix ?? ''}
              </span>
            )}
          </span>
        </div>
      )}
    </div>
  )
}
