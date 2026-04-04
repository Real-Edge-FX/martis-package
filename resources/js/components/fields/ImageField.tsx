import { useState, useRef, useMemo } from 'react'
import type { FieldDisplayProps, FieldInputProps } from './types'
import { Image as ImageIcon } from '@phosphor-icons/react'

interface ImageValue {
  path: string
  url: string
  name: string
  thumbnailUrl?: string
}

function isImageValue(v: unknown): v is ImageValue {
  return v !== null && typeof v === 'object' && 'url' in (v as Record<string, unknown>)
}

export function ImageFieldDisplay({ value }: FieldDisplayProps) {
  if (!isImageValue(value)) {
    return <span className="martis-text-muted">—</span>
  }

  return (
    <a href={value.url} target="_blank" rel="noopener noreferrer" className="inline-block">
      <img
        src={value.thumbnailUrl ?? value.url}
        alt={value.name}
        className="max-h-24 rounded border object-cover"
        style={{ borderColor: 'var(--martis-border)' }}
      />
    </a>
  )
}

export function ImageFieldInput({ field, value, onChange, error }: FieldInputProps) {
  const inputRef = useRef<HTMLInputElement>(null)
  const [dragOver, setDragOver] = useState(false)

  const currentFile = value instanceof window.File ? value : null
  const existingImage = isImageValue(value) ? value : null
  const hasValue = currentFile !== null || existingImage !== null

  const maxSize = (field as unknown as Record<string, unknown>).maxSize as number | undefined

  const previewUrl = useMemo(() => {
    if (currentFile) return URL.createObjectURL(currentFile)
    if (existingImage) return existingImage.thumbnailUrl ?? existingImage.url
    return null
  }, [currentFile, existingImage])

  function handleFile(file: globalThis.File) {
    if (!file.type.startsWith('image/')) return
    if (maxSize && file.size > maxSize * 1024) return
    onChange(file)
  }

  function handleDrop(e: React.DragEvent) {
    e.preventDefault()
    setDragOver(false)
    const file = e.dataTransfer.files[0]
    if (file) handleFile(file)
  }

  function handleClear() {
    onChange(null)
    if (inputRef.current) inputRef.current.value = ''
  }

  return (
    <div className="flex flex-col gap-1">
      <div
        className={`relative rounded-md border transition-colors ${dragOver ? 'border-indigo-500 bg-indigo-500/10' : ''}`}
        style={{
          backgroundColor: 'var(--martis-input-bg)',
          borderColor: error ? '#ef4444' : 'var(--martis-border)',
        }}
        onDragOver={(e) => { e.preventDefault(); setDragOver(true) }}
        onDragLeave={() => setDragOver(false)}
        onDrop={handleDrop}
      >
        {hasValue ? (
          <div className="flex items-start gap-3 p-3">
            {previewUrl && (
              <img
                src={previewUrl}
                alt="Preview"
                className="h-20 w-20 flex-shrink-0 rounded border object-cover"
                style={{ borderColor: 'var(--martis-border)' }}
              />
            )}
            <div className="flex flex-1 flex-col gap-1">
              <span className="truncate text-sm martis-text">
                {currentFile ? currentFile.name : existingImage?.name}
              </span>
              <div className="flex gap-2">
                <button
                  type="button"
                  onClick={() => inputRef.current?.click()}
                  className="text-xs hover:underline"
                  style={{ color: 'var(--martis-accent)' }}
                >
                  Change
                </button>
                <button
                  type="button"
                  onClick={handleClear}
                  className="text-xs text-red-500 hover:underline"
                >
                  Remove
                </button>
              </div>
            </div>
          </div>
        ) : (
          <button
            type="button"
            onClick={() => inputRef.current?.click()}
            className="flex w-full flex-col items-center gap-2 px-4 py-6 text-sm martis-text-muted"
          >
            <ImageIcon size={28} />
            <span>Click or drag image here</span>
          </button>
        )}

        <input
          ref={inputRef}
          id={field.attribute}
          name={field.attribute}
          type="file"
          accept="image/*"
          disabled={field.readonly}
          className="sr-only"
          onChange={(e) => {
            const file = e.target.files?.[0]
            if (file) handleFile(file)
          }}
        />
      </div>

      {maxSize && (
        <span className="text-xs martis-text-muted">
          Max: {maxSize >= 1024 ? `${(maxSize / 1024).toFixed(0)} MB` : `${maxSize} KB`}
        </span>
      )}
      {error && <small className="text-red-500">{error}</small>}
    </div>
  )
}
