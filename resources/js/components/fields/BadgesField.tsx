import type { FieldDisplayProps, FieldInputProps } from './types'
import { resolveBadgeType } from './BadgeField'

interface BadgesItem {
  label: string
  type: string | null
  tooltip: string | null
}

function isBadgesItem(v: unknown): v is BadgesItem {
  return typeof v === 'object' && v !== null && typeof (v as { label?: unknown }).label === 'string'
}

/**
 * Several badge pills for one value. Reuses the Badge pill styles and the
 * `map`/`types` resolution; labels are plain React text and a tooltip goes
 * through the global tooltip (`data-pr-tooltip`), never a native `title`.
 */
export function BadgesFieldDisplay({ field, value }: FieldDisplayProps) {
  const map = ((field as Record<string, unknown>).map as Record<string, string> | undefined) ?? {}
  const types = ((field as Record<string, unknown>).types as Record<string, string> | undefined) ?? {}
  const withDot = ((field as Record<string, unknown>).withDot as boolean | undefined) ?? true

  const items = Array.isArray(value) ? value.filter(isBadgesItem) : []

  if (items.length === 0) {
    return <span className="martis-text-muted">—</span>
  }

  return (
    <ul role="list" className="flex flex-wrap items-center" style={{ gap: '0.25rem', margin: 0, padding: 0, listStyle: 'none' }}>
      {items.map((item, index) => {
        const { style } = resolveBadgeType(item.type ?? '', map, types)
        return (
          <li
            key={`${index}-${item.label}`}
            role="listitem"
            className="martis-badge"
            data-pr-tooltip={item.tooltip ?? undefined}
            data-pr-position={item.tooltip ? 'top' : undefined}
            style={{ backgroundColor: style.bg, color: style.text, borderColor: style.border }}
          >
            {withDot ? <span className="martis-badge-dot" aria-hidden="true" /> : null}
            {item.label}
          </li>
        )
      })}
    </ul>
  )
}

/** Badges is display-only; in a form context it renders the read-only pills. */
export function BadgesFieldInput({ field, value }: FieldInputProps) {
  return (
    <div className="flex items-center" style={{ minHeight: '2.5rem' }}>
      <BadgesFieldDisplay field={field} value={value} />
    </div>
  )
}
