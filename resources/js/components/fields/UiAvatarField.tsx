import { UserIcon } from '@phosphor-icons/react'
import { initialsAvatarStyle } from '@/lib/avatarPalette'
import type { FieldDisplayProps, FieldInputProps } from './types'

interface UiAvatarValue {
  initials: string
  color: string
  /** Slot of the theme's `--martis-avatar-N` tokens; null when `colorFrom()` gave `color`. */
  palette?: number | null
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

function isUiAvatarValue(v: unknown): v is UiAvatarValue {
  return typeof v === 'object' && v !== null && 'initials' in (v as object) && 'color' in (v as object)
}

export function UiAvatarFieldDisplay({ value }: FieldDisplayProps) {
  if (!isUiAvatarValue(value)) {
    return <span className="martis-text-muted">—</span>
  }
  const shapeClass = resolveShapeClass(value.shape)
  // The server's palette slot paints the circle with the theme token; a
  // `color` without a slot comes from `colorFrom()`. F7-35 — a payload
  // with neither falls back to the seed's slot so two users never clash.
  const { backgroundColor, color } = initialsAvatarStyle({
    palette: value.palette,
    color: value.color,
    seed: value.seed || value.initials || '',
  })

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
      style={{ backgroundColor, color }}
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
