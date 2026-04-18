import { useRef, useState } from 'react'
import type { FieldDisplayProps, FieldInputProps } from './types'

interface AvatarSchema {
  shape?: 'circle' | 'rounded' | 'squared'
  acceptedTypes?: string[]
}

type StoredValue = {
  url?: string | null
  name?: string | null
  path?: string | null
  thumbnailUrl?: string | null
  isFallback?: boolean
} | File | null | undefined

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

function resolveUrl(value: StoredValue): string | null {
  if (!value) return null
  if (value instanceof File) {
    return URL.createObjectURL(value)
  }
  if (typeof value === 'object') {
    return value.thumbnailUrl ?? value.url ?? null
  }
  return null
}

export function AvatarFieldDisplay({ field, value }: FieldDisplayProps) {
  const schema = field as unknown as AvatarSchema
  const url = resolveUrl(value as StoredValue)
  const shapeClass = resolveShapeClass(schema.shape)

  if (!url) {
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

  const inputRef = useRef<HTMLInputElement | null>(null)
  const [preview, setPreview] = useState<string | null>(resolveUrl(value as StoredValue))

  const handleFile = (file: File | null) => {
    onChange(file)
    setPreview(file ? URL.createObjectURL(file) : null)
  }

  return (
    <div className={`martis-avatar-input${error ? ' has-error' : ''}`}>
      <button
        type="button"
        className={`martis-avatar ${shapeClass} is-interactive`}
        onClick={() => inputRef.current?.click()}
        aria-label="Upload avatar"
      >
        {preview ? (
          <img src={preview} alt="" />
        ) : (
          <span className="martis-avatar-placeholder">＋</span>
        )}
      </button>
      <div className="martis-avatar-input-meta">
        <button type="button" className="martis-btn-secondary" onClick={() => inputRef.current?.click()}>
          Escolher ficheiro
        </button>
        {preview && (
          <button type="button" className="martis-btn-secondary" onClick={() => handleFile(null)}>
            Remover
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
