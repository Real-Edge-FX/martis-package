import { UserIcon } from '@phosphor-icons/react'
import { avatarColorForSeed, avatarHexForSeed } from '@/lib/avatarPalette'
import type { FieldDisplayProps, FieldInputProps } from './types'

interface UiAvatarValue {
  initials: string
  color: string
  seed: string
  shape: 'circle' | 'rounded' | 'squared'
}

function resolveShapeClass(shape: string | undefined): string {
  switch (shape) {
    case 'squared':
      return 'martis-avatar-squared'
    case 'rounded':
      return 'martis-avatar-rounded'
    case 'circle':
    default:
      return 'martis-avatar-circle'
  }
}

/**
 * Pick a legible text colour (black or white) for the given hex bg using
 * the WCAG luminance approximation. Avoids hard-coding white on every
 * swatch so light brand colours (yellow, cyan) remain readable.
 */
function readableTextColor(hex: string | null): string {
  if (!hex) return '#fff'
  const h = hex.replace('#', '')
  if (h.length !== 6) return '#fff'
  const r = parseInt(h.substring(0, 2), 16)
  const g = parseInt(h.substring(2, 4), 16)
  const b = parseInt(h.substring(4, 6), 16)
  const luminance = (0.299 * r + 0.587 * g + 0.114 * b) / 255
  return luminance > 0.6 ? '#0f172a' : '#ffffff'
}

function isUiAvatarValue(v: unknown): v is UiAvatarValue {
  return typeof v === 'object' && v !== null && 'initials' in (v as object) && 'color' in (v as object)
}

export function UiAvatarFieldDisplay({ value }: FieldDisplayProps) {
  if (!isUiAvatarValue(value)) {
    return <span className="martis-text-muted">—</span>
  }
  const shapeClass = resolveShapeClass(value.shape)
  // F7-35 — backend may omit `color` (or pass an empty string); fall back
  // to the deterministic 16-hue palette so two users never clash.
  const seed = value.seed || value.initials || ''
  const bg = value.color && value.color.length > 0
    ? value.color
    : avatarColorForSeed(seed)
  // Resolve to hex for the readable-text-colour computation when we
  // picked the CSS-var fallback path (readableTextColor needs a hex).
  const textColor = value.color && value.color.length > 0
    ? readableTextColor(value.color)
    : readableTextColor(avatarHexForSeed(seed))

  // F7-36 — empty initials → neutral user glyph instead of '?'.
  if (!value.initials) {
    return (
      <span
        className={`martis-avatar ${shapeClass} martis-ui-avatar martis-avatar-fallback`}
        aria-label={value.seed}
      >
        <UserIcon size={14} weight="bold" />
      </span>
    )
  }

  return (
    <span
      className={`martis-avatar ${shapeClass} martis-ui-avatar`}
      style={{
        backgroundColor: bg,
        color: textColor,
      }}
      aria-label={value.seed}
    >
      {value.initials}
    </span>
  )
}

// UiAvatar is display-only; input renders the same thing.
export function UiAvatarFieldInput(props: FieldInputProps) {
  return <UiAvatarFieldDisplay {...(props as unknown as FieldDisplayProps)} />
}
