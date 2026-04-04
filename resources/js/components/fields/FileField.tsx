import { useState, useRef } from 'react'
import type { FieldDisplayProps, FieldInputProps } from './types'
import { File as FileIcon, DownloadSimple, Trash, UploadSimple } from '@phosphor-icons/react'

interface FileValue {
  path: string
  url: string
  name: string
}

function isFileValue(v: unknown): v is FileValue {
  return v !== null && typeof v === 'object' && 'url' in (v as Record<string, unknown>)
}

export function FileFieldDisplay({ value }: FieldDisplayProps) {
  if (!isFileValue(value)) {
    return <span className="martis-text-muted">—</span>
  }

  return (
    <a
      href={value.url}
      target="_blank"
      rel="noopener noreferrer"
      className="inline-flex items-center gap-2 text-sm hover:underline"
      style={{ color: 'var(--martis-accent)' }}
    >
      <FileIcon size={16} />
      {value.name}
      <DownloadSimple size={14} />
    </a>
  )
}

export function FileFieldInput({ field, value, onChange, error }: FieldInputProps) {
  const inputRef = useRef<HTMLInputElement>(null)
  const [dragOver, setDragOver] = useState(false)

  const currentFile = value instanceof window.File ? value : null
  const existingFile = isFileValue(value) ? value : null
  const hasValue = currentFile !== null || existingFile !== null

  const acceptedTypes = (field as unknown as Record<string, unknown>).acceptedTypes as string[] | undefined
  const maxSize = (field as unknown as Record<string, unknown>).maxSize as number | undefined

  const accept = acceptedTypes?.length
    ? acceptedTypes.map((t) => `.${t}`).join(',')
    : undefined

  function handleFile(file: globalThis.File) {
    if (maxSize && file.size > maxSize * 1024) {
      return
    }
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
        className={`relative flex items-center gap-3 rounded-md border px-4 py-3 transition-colors ${dragOver ? 'border-indigo-500 bg-indigo-500/10' : ''}`}
        style={{
          backgroundColor: 'var(--martis-input-bg)',
          borderColor: error ? '#ef4444' : 'var(--martis-border)',
        }}
        onDragOver={(e) => { e.preventDefault(); setDragOver(true) }}
        onDragLeave={() => setDragOver(false)}
        onDrop={handleDrop}
      >
        {hasValue ? (
          <>
            <FileIcon size={20} className="martis-text-muted flex-shrink-0" />
            <span className="flex-1 truncate text-sm martis-text">
              {currentFile ? currentFile.name : existingFile?.name}
            </span>
            <button
              type="button"
              onClick={handleClear}
              className="flex-shrink-0 rounded p-1 hover:bg-red-500/10"
            >
              <Trash size={16} className="text-red-500" />
            </button>
          </>
        ) : (
          <button
            type="button"
            onClick={() => inputRef.current?.click()}
            className="flex w-full items-center gap-2 text-sm martis-text-muted"
          >
            <UploadSimple size={20} />
            <span>Choose file or drag here</span>
          </button>
        )}

        <input
          ref={inputRef}
          id={field.attribute}
          name={field.attribute}
          type="file"
          accept={accept}
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
