/**
 * Shared badge colour resolution.
 *
 * Every `.martis-badge` renderer (Badge, Boolean, MultiSelect, ...) maps a
 * keyword or hex string to the { bg, text, border } triad that the design
 * system expects. Extracted from BadgeField so Boolean/MultiSelect colour
 * props can reuse it.
 */

export type BadgeStyle = { bg: string; text: string; border: string }

export const BADGE_TYPE_STYLES: Record<string, BadgeStyle> = {
  info:    { bg: 'var(--martis-badge-info-bg)',    text: 'var(--martis-badge-info-text)',    border: 'var(--martis-badge-info-border)' },
  success: { bg: 'var(--martis-badge-success-bg)', text: 'var(--martis-badge-success-text)', border: 'var(--martis-badge-success-border)' },
  warning: { bg: 'var(--martis-badge-warning-bg)', text: 'var(--martis-badge-warning-text)', border: 'var(--martis-badge-warning-border)' },
  danger:  { bg: 'var(--martis-badge-danger-bg)',  text: 'var(--martis-badge-danger-text)',  border: 'var(--martis-badge-danger-border)' },
}

export const BADGE_NEUTRAL_STYLE: BadgeStyle = {
  bg: 'var(--martis-hover)',
  text: 'var(--martis-text-muted)',
  border: 'var(--martis-border)',
}

export const BADGE_DEFAULT_STYLE: BadgeStyle = {
  bg: 'var(--martis-surface-alt)',
  text: 'var(--martis-text)',
  border: 'var(--martis-border)',
}

export function isHexColor(s: string): boolean {
  return /^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/.test(s)
}

export function hexToRgba(hex: string, alpha: number): string {
  const h = hex.replace('#', '')
  const full = h.length === 3 ? h.split('').map(c => c + c).join('') : h
  const r = parseInt(full.substring(0, 2), 16)
  const g = parseInt(full.substring(2, 4), 16)
  const b = parseInt(full.substring(4, 6), 16)
  return `rgba(${r}, ${g}, ${b}, ${alpha})`
}

export function styleFromHex(hex: string): BadgeStyle {
  return {
    bg: hexToRgba(hex, 0.15),
    text: hex,
    border: hexToRgba(hex, 0.3),
  }
}

/**
 * Resolve a colour keyword or hex string into a BadgeStyle. Unknown
 * inputs fall back to the default surface-alt chip.
 */
export function resolveBadgeStyle(color: string | null | undefined): BadgeStyle {
  if (!color) return BADGE_DEFAULT_STYLE
  if (color === 'neutral') return BADGE_NEUTRAL_STYLE
  if (BADGE_TYPE_STYLES[color]) return BADGE_TYPE_STYLES[color]
  if (isHexColor(color)) return styleFromHex(color)
  return BADGE_DEFAULT_STYLE
}
