import type { FieldDisplayProps, FieldInputProps } from './types'
import { resolveBadgeStyle, type BadgeStyle } from './badgeStyles'

// ---------------------------------------------------------------------------
// Color mapping: badge type → CSS color vars / class
// ---------------------------------------------------------------------------

function resolveBadgeType(
  value: unknown,
  map: Record<string, string>,
  types: Record<string, string>,
): { type: string; style: BadgeStyle } {
  const strVal = String(value ?? '')
  const badgeType = map[strVal] ?? strVal
  const colorKey = types[badgeType] ?? badgeType
  return { type: badgeType, style: resolveBadgeStyle(colorKey) }
}

// ---------------------------------------------------------------------------
// Display
// ---------------------------------------------------------------------------

/**
 * Shape emitted by PHP's `Badge::resolveBadgeUsing()` per-row resolver.
 * When present, we use its keys verbatim; anything null/undefined falls
 * back to the static maps already serialised on the field schema.
 */
interface ResolvedBadgePayload {
  __martisBadge: true
  value: unknown
  type: string | null
  label: string | null
  icon: string | null
}

function isResolvedBadgePayload(v: unknown): v is ResolvedBadgePayload {
  return typeof v === 'object' && v !== null && (v as { __martisBadge?: unknown }).__martisBadge === true
}

export function BadgeFieldDisplay({ field, value }: FieldDisplayProps) {
  const map = ((field as Record<string, unknown>).map as Record<string, string> | undefined) ?? {}
  const labels = ((field as Record<string, unknown>).labels as Record<string, string> | undefined) ?? {}
  const types = ((field as Record<string, unknown>).types as Record<string, string> | undefined) ?? {}
  const icons = ((field as Record<string, unknown>).icons as Record<string, string> | undefined) ?? {}
  const withIcons = (field as Record<string, unknown>).withIcons as boolean | undefined

  // Per-row resolver wins: grab its keys, fall back to static maps for any
  // field the resolver left blank.
  const resolved = isResolvedBadgePayload(value) ? value : null
  const rawValue = resolved ? resolved.value : value

  if (rawValue === null || rawValue === undefined || rawValue === '') {
    if (!resolved || (!resolved.type && !resolved.label)) {
      return <span className="martis-text-muted">—</span>
    }
  }

  const { type, style } = resolveBadgeType(resolved?.type ?? rawValue, map, types)
  const icon = resolved?.icon ?? (withIcons ? icons[type] : null)
  const label = resolved?.label ?? labels[String(rawValue)] ?? String(rawValue)

  const withDot = ((field as Record<string, unknown>).withDot as boolean | undefined) ?? true

  return (
    <span
      className="martis-badge"
      style={{
        backgroundColor: style.bg,
        color: style.text,
        borderColor: style.border,
      }}
    >
      {icon ? (
        <span className="shrink-0" aria-hidden="true" style={{ fontSize: '0.65rem' }}>
          {icon}
        </span>
      ) : withDot ? (
        <span className="martis-badge-dot" aria-hidden="true" />
      ) : null}
      {label}
    </span>
  )
}

// ---------------------------------------------------------------------------
// Input — Badge is display-only; this is a no-op placeholder
// Developers should use Select or another input field to edit badge values.
// ---------------------------------------------------------------------------

export function BadgeFieldInput({ field, value }: FieldInputProps) {
  // Badge is intentionally display-only. Renders as a clean read-only pill in form context.
  return (
    <div className="flex items-center" style={{ minHeight: '2.5rem' }}>
      <BadgeFieldDisplay field={field} value={value} />
    </div>
  )
}
