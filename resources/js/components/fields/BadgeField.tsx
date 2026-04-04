import type { FieldDisplayProps, FieldInputProps } from './types'

// ---------------------------------------------------------------------------
// Color mapping: badge type → CSS color vars / class
// ---------------------------------------------------------------------------

const TYPE_STYLES: Record<string, { bg: string; text: string; border: string }> = {
  info:    { bg: 'var(--martis-badge-info-bg, #dbeafe)',    text: 'var(--martis-badge-info-text, #1e40af)',    border: 'var(--martis-badge-info-border, #bfdbfe)' },
  success: { bg: 'var(--martis-badge-success-bg, #dcfce7)', text: 'var(--martis-badge-success-text, #15803d)', border: 'var(--martis-badge-success-border, #bbf7d0)' },
  warning: { bg: 'var(--martis-badge-warning-bg, #fef9c3)', text: 'var(--martis-badge-warning-text, #a16207)', border: 'var(--martis-badge-warning-border, #fde68a)' },
  danger:  { bg: 'var(--martis-badge-danger-bg, #fee2e2)',  text: 'var(--martis-badge-danger-text, #b91c1c)',  border: 'var(--martis-badge-danger-border, #fecaca)' },
}

const DEFAULT_STYLE = { bg: 'var(--martis-surface-alt, #f3f4f6)', text: 'var(--martis-text, #374151)', border: 'var(--martis-border, #e5e7eb)' }

function resolveBadgeType(
  value: unknown,
  map: Record<string, string>,
  types: Record<string, string>,
): { type: string; style: typeof DEFAULT_STYLE } {
  const strVal = String(value ?? '')
  const badgeType = map[strVal] ?? strVal
  const colorKey = types[badgeType] ?? badgeType
  const style = TYPE_STYLES[colorKey] ?? DEFAULT_STYLE

  return { type: badgeType, style }
}

// ---------------------------------------------------------------------------
// Display
// ---------------------------------------------------------------------------

export function BadgeFieldDisplay({ field, value }: FieldDisplayProps) {
  if (value === null || value === undefined || value === '') {
    return <span className="martis-text-muted">—</span>
  }

  const map = ((field as Record<string, unknown>).map as Record<string, string> | undefined) ?? {}
  const types = ((field as Record<string, unknown>).types as Record<string, string> | undefined) ?? {}
  const icons = ((field as Record<string, unknown>).icons as Record<string, string> | undefined) ?? {}
  const withIcons = (field as Record<string, unknown>).withIcons as boolean | undefined

  const { type, style } = resolveBadgeType(value, map, types)
  const icon = withIcons ? icons[type] : null
  const label = type || String(value)

  return (
    <span
      className="inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-medium"
      style={{
        backgroundColor: style.bg,
        color: style.text,
        border: `1px solid ${style.border}`,
      }}
    >
      {icon && (
        <span className="shrink-0" aria-hidden="true" style={{ fontSize: '0.65rem' }}>
          {icon}
        </span>
      )}
      {label}
    </span>
  )
}

// ---------------------------------------------------------------------------
// Input — Badge is display-only; this is a no-op placeholder
// Developers should use Select or another input field to edit badge values.
// ---------------------------------------------------------------------------

export function BadgeFieldInput({ field, value }: FieldInputProps) {
  // Badge is intentionally display-only. If shown in a form context
  // (e.g. via ->showOnForms()), it renders as read-only display.
  return (
    <div className="flex items-center gap-2">
      <BadgeFieldDisplay field={field} value={value} />
      <span className="text-xs" style={{ color: 'var(--martis-text-muted)' }}>
        (read-only)
      </span>
    </div>
  )
}
