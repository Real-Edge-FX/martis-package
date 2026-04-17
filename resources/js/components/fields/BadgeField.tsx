import type { FieldDisplayProps, FieldInputProps } from './types'

// ---------------------------------------------------------------------------
// Color mapping: badge type → CSS color vars / class
// ---------------------------------------------------------------------------

const TYPE_STYLES: Record<string, { bg: string; text: string; border: string }> = {
  info:    { bg: 'var(--martis-badge-info-bg)',    text: 'var(--martis-badge-info-text)',    border: 'var(--martis-badge-info-border)' },
  success: { bg: 'var(--martis-badge-success-bg)', text: 'var(--martis-badge-success-text)', border: 'var(--martis-badge-success-border)' },
  warning: { bg: 'var(--martis-badge-warning-bg)', text: 'var(--martis-badge-warning-text)', border: 'var(--martis-badge-warning-border)' },
  danger:  { bg: 'var(--martis-badge-danger-bg)',  text: 'var(--martis-badge-danger-text)',  border: 'var(--martis-badge-danger-border)' },
}

const DEFAULT_STYLE = { bg: 'var(--martis-surface-alt)', text: 'var(--martis-text)', border: 'var(--martis-border)' }

/** Check if a string looks like a hex color (#rgb, #rrggbb) */
function isHexColor(s: string): boolean {
  return /^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/.test(s)
}

/** Convert hex to rgba string */
function hexToRgba(hex: string, alpha: number): string {
  const h = hex.replace('#', '')
  const full = h.length === 3 ? h.split('').map(c => c + c).join('') : h
  const r = parseInt(full.substring(0, 2), 16)
  const g = parseInt(full.substring(2, 4), 16)
  const b = parseInt(full.substring(4, 6), 16)
  return `rgba(${r}, ${g}, ${b}, ${alpha})`
}

/** Generate a badge style from a hex color */
function styleFromHex(hex: string): { bg: string; text: string; border: string } {
  return {
    bg: hexToRgba(hex, 0.15),
    text: hex,
    border: hexToRgba(hex, 0.3),
  }
}

function resolveBadgeType(
  value: unknown,
  map: Record<string, string>,
  types: Record<string, string>,
): { type: string; style: typeof DEFAULT_STYLE } {
  const strVal = String(value ?? '')
  const badgeType = map[strVal] ?? strVal
  const colorKey = types[badgeType] ?? badgeType

  // 1. Try built-in type styles (info, success, warning, danger)
  if (TYPE_STYLES[colorKey]) return { type: badgeType, style: TYPE_STYLES[colorKey] }

  // 2. Try custom hex color from types()
  if (isHexColor(colorKey)) return { type: badgeType, style: styleFromHex(colorKey) }

  // 3. Fallback
  return { type: badgeType, style: DEFAULT_STYLE }
}

// ---------------------------------------------------------------------------
// Display
// ---------------------------------------------------------------------------

export function BadgeFieldDisplay({ field, value }: FieldDisplayProps) {
  if (value === null || value === undefined || value === '') {
    return <span className="martis-text-muted">—</span>
  }

  const map = ((field as Record<string, unknown>).map as Record<string, string> | undefined) ?? {}
  const labels = ((field as Record<string, unknown>).labels as Record<string, string> | undefined) ?? {}
  const types = ((field as Record<string, unknown>).types as Record<string, string> | undefined) ?? {}
  const icons = ((field as Record<string, unknown>).icons as Record<string, string> | undefined) ?? {}
  const withIcons = (field as Record<string, unknown>).withIcons as boolean | undefined

  const { type, style } = resolveBadgeType(value, map, types)
  const icon = withIcons ? icons[type] : null
  const label = labels[String(value)] ?? String(value)

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
  // Badge is intentionally display-only. Renders as a clean read-only pill in form context.
  return (
    <div className="flex items-center" style={{ minHeight: '2.5rem' }}>
      <BadgeFieldDisplay field={field} value={value} />
    </div>
  )
}
