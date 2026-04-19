import { ArrowUpIcon, ArrowDownIcon, MinusIcon } from '@phosphor-icons/react'
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

  return (
    <div className="text-center">
      <p
        className="text-3xl font-bold"
        style={{ color: 'var(--martis-text)' }}
      >
        {formattedValue}
      </p>

      {previous !== undefined && value !== previous && (
        <div className="mt-2 flex items-center justify-center gap-1.5">
          {(change ?? 0) > 0 ? (
            <ArrowUpIcon size={14} weight="bold" style={{ color: 'var(--martis-success)' }} />
          ) : (change ?? 0) < 0 ? (
            <ArrowDownIcon size={14} weight="bold" style={{ color: 'var(--martis-danger)' }} />
          ) : (
            <MinusIcon size={14} weight="bold" style={{ color: 'var(--martis-text-muted)' }} />
          )}
          {change !== undefined && (
            <span
              className="text-sm font-medium"
              style={{
                color:
                  change > 0
                    ? 'var(--martis-success)'
                    : change < 0
                      ? 'var(--martis-danger)'
                      : 'var(--martis-text-muted)',
              }}
            >
              {change > 0 ? '+' : ''}
              {change}%
            </span>
          )}
          <span
            className="text-xs"
            style={{ color: 'var(--martis-text-muted)' }}
          >
            {t('vs', 'vs')} {prefix ?? ''}{previous.toLocaleString()}{suffix ?? ''}
          </span>
        </div>
      )}
    </div>
  )
}
