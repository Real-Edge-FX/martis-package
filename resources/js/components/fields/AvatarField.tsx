import { useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { UserIcon } from '@phosphor-icons/react'
import { avatarColorForSeed, avatarHexForSeed } from '@/lib/avatarPalette'
import type { FieldDisplayProps, FieldInputProps } from './types'

interface AvatarSchema {
  shape?: 'circle' | 'rounded' | 'squared'
  acceptedTypes?: string[]
}

interface StoredPayload {
  url?: string | null
  name?: string | null
  path?: string | null
  thumbnailUrl?: string | null
  isFallback?: boolean
  // Zero-config initials fallback — no external service.
  isInitialsFallback?: boolean
  initials?: string
  color?: string
  seed?: string
}

type Value = StoredPayload | File | null | undefined

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

function resolveUrl(value: Value): string | null {
  if (!value) return null
  if (value instanceof File) {
    return URL.createObjectURL(value)
  }
  if (typeof value === 'object') {
    return value.thumbnailUrl ?? value.url ?? null
  }
  return null
}

function asPayload(value: Value): StoredPayload | null {
  if (!value || value instanceof File) return null
  return value
}

/** WCAG-ish contrast pick: return black on pale bg, white otherwise. */
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

function InitialsCircle({
  initials,
  color,
  seed,
  shapeClass,
}: {
  initials: string
  color: string | null | undefined
  seed: string | null | undefined
  shapeClass: string
}) {
  // F7-36 — empty initials → muted user glyph instead of '?'.
  if (!initials) {
    return (
      <span
        className={`martis-avatar ${shapeClass} martis-ui-avatar martis-avatar-fallback`}
        aria-hidden="true"
      >
        <UserIcon size={14} weight="bold" />
      </span>
    )
  }

  // F7-35 — fall back to the deterministic 16-hue palette when no
  // explicit colour is supplied. The CSS variable resolves at paint
  // time so the colour stays consistent across themes.
  const bg = color && color.length > 0 ? color : avatarColorForSeed(seed)
  const textColor = color && color.length > 0
    ? readableTextColor(color)
    : readableTextColor(avatarHexForSeed(seed))

  return (
    <span
      className={`martis-avatar ${shapeClass} martis-ui-avatar`}
      style={{ backgroundColor: bg, color: textColor }}
      aria-hidden="true"
    >
      {initials}
    </span>
  )
}

export function AvatarFieldDisplay({ field, value }: FieldDisplayProps) {
  const schema = field as unknown as AvatarSchema
  const shapeClass = resolveShapeClass(schema.shape)
  const payload = asPayload(value as Value)

  // Zero-config initials fallback — rendered inline.
  if (payload?.isInitialsFallback && payload.initials !== undefined) {
    return (
      <InitialsCircle
        initials={payload.initials}
        color={payload.color}
        seed={payload.seed ?? payload.name ?? payload.initials}
        shapeClass={shapeClass}
      />
    )
  }

  const url = resolveUrl(value as Value)
  if (!url) {
    // F7-36 — display-mode fallback when a record has no avatar at all
    // (no image, no initials seed). Drops the bare em-dash for a muted
    // user glyph that visually slots into row layouts.
    if (payload?.seed || payload?.name) {
      return (
        <InitialsCircle
          initials=""
          color={payload?.color}
          seed={payload?.seed ?? payload?.name}
          shapeClass={shapeClass}
        />
      )
    }
    return <span className="martis-text-muted">—</span>
  }

  return (
    <span className={`martis-avatar ${shapeClass}`}>
      <img src={url} alt="" loading="lazy" />
    </span>
  )
}

export function AvatarFieldInput({ field, value, onChange, error }: FieldInputProps) {
  const schema = field as unknown as AvatarSchema
  const shapeClass = resolveShapeClass(schema.shape)
  const accepted = (schema.acceptedTypes ?? ['jpg', 'jpeg', 'png', 'webp'])
    .map((ext) => `.${ext}`)
    .join(',')

  const { t } = useTranslation('messages')
  const inputRef = useRef<HTMLInputElement | null>(null)
  const payload = asPayload(value as Value)
  const initialPreview = resolveUrl(value as Value)
  const [preview, setPreview] = useState<string | null>(initialPreview)

  const handleFile = (file: File | null) => {
    onChange(file)
    setPreview(file ? URL.createObjectURL(file) : null)
  }

  const showInitialsFallback =
    preview === null && payload?.isInitialsFallback && payload.initials !== undefined

  return (
    <div className={`martis-avatar-input${error ? ' has-error' : ''}`}>
      <button
        type="button"
        className={`martis-avatar ${shapeClass} is-interactive${preview || showInitialsFallback ? ' has-image' : ''}`}
        onClick={() => inputRef.current?.click()}
        aria-label="Upload avatar"
        style={
          showInitialsFallback
            ? {
                backgroundColor: payload?.color ?? '#475569',
                color: readableTextColor(payload?.color ?? '#475569'),
                borderStyle: 'solid',
              }
            : undefined
        }
      >
        {preview ? (
          <img src={preview} alt="" />
        ) : showInitialsFallback ? (
          <span className="martis-avatar-initials-text">{payload.initials}</span>
        ) : (
          <span className="martis-avatar-placeholder">＋</span>
        )}
      </button>
      <div className="martis-avatar-input-meta">
        <button type="button" className="martis-btn-secondary" onClick={() => inputRef.current?.click()}>
          {t('avatar_choose_file', 'Choose file')}
        </button>
        {preview && (
          <button type="button" className="martis-btn-secondary" onClick={() => handleFile(null)}>
            {t('avatar_remove', 'Remove')}
          </button>
        )}
      </div>
      <input
        ref={inputRef}
        type="file"
        accept={accepted}
        style={{ display: 'none' }}
        onChange={(e) => handleFile(e.target.files?.[0] ?? null)}
      />
      {error && <p className="martis-field-error">{error}</p>}
    </div>
  )
}
