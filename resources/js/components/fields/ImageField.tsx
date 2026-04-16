import { useTranslation } from "react-i18next"
import { useState, useRef, useMemo, useCallback } from 'react'
import type { FieldDisplayProps, FieldInputProps } from './types'
import { ImageIcon, PlusIcon, TrashIcon } from '@phosphor-icons/react'
import { useToastSafe } from "@/contexts/ToastContext"

interface ImageValue {
  path: string
  url: string
  name: string
  thumbnailUrl?: string
}

function isImageValue(v: unknown): v is ImageValue {
  return v !== null && typeof v === 'object' && 'url' in (v as Record<string, unknown>)
}

function isImageValueArray(v: unknown): v is ImageValue[] {
  return Array.isArray(v) && v.every(isImageValue)
}

// ---------------------------------------------------------------------------
// Display
// ---------------------------------------------------------------------------

export function ImageFieldDisplay({ value, field }: FieldDisplayProps) {
  const multiple = (field as unknown as Record<string, unknown>).multiple as boolean | undefined

  if (multiple && isImageValueArray(value)) {
    if (value.length === 0) return <span className="martis-text-muted">—</span>
    return (
      <div className="flex flex-wrap gap-2">
        {value.map((img) => (
          <a key={img.path} href={img.url} target="_blank" rel="noopener noreferrer" className="inline-block">
            <img
              src={img.thumbnailUrl ?? img.url}
              alt={img.name}
              className="h-16 w-16 rounded border object-cover"
              style={{ borderColor: 'var(--martis-border)' }}
            />
          </a>
        ))}
      </div>
    )
  }

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

// ---------------------------------------------------------------------------
// Input — Single mode
// ---------------------------------------------------------------------------

function SingleImageInput({ field, value, onChange, error }: FieldInputProps) {
  const inputRef = useRef<HTMLInputElement>(null)
  const [dragOver, setDragOver] = useState(false)
  const { addToast } = useToastSafe()
  const { t: tMsg } = useTranslation("messages")

  const currentFile = value instanceof window.File ? value : null
  const existingImage = isImageValue(value) ? value : null
  const hasValue = currentFile !== null || existingImage !== null

  const maxSize = (field as unknown as Record<string, unknown>).maxSize as number | undefined
  const acceptedTypes = (field as unknown as Record<string, unknown>).acceptedTypes as string[] | undefined
  const showFileInfo = (field as unknown as Record<string, unknown>).showFileInfo as boolean | undefined

  const acceptAttr = useMemo(() => {
    if (acceptedTypes && acceptedTypes.length > 0) {
      return acceptedTypes.map(t => `.${t}`).join(',')
    }
    return 'image/*'
  }, [acceptedTypes])

  const previewUrl = useMemo(() => {
    if (currentFile) return URL.createObjectURL(currentFile)
    if (existingImage) return existingImage.thumbnailUrl ?? existingImage.url
    return null
  }, [currentFile, existingImage])

  function formatSize(kb: number): string {
    return kb >= 1024 ? `${(kb / 1024).toFixed(0)} MB` : `${kb} KB`
  }

  function handleFile(file: globalThis.File) {
    if (acceptedTypes && acceptedTypes.length > 0) {
      const ext = file.name.split('.').pop()?.toLowerCase() ?? ''
      if (!acceptedTypes.includes(ext)) {
        addToast('error', tMsg('file_type_not_allowed', `Image type .${ext} is not allowed. Accepted: ${acceptedTypes.join(', ')}`))
        return
      }
    } else if (!file.type.startsWith('image/')) {
      addToast('error', tMsg('file_not_image', `"${file.name}" is not a valid image file.`))
      return
    }
    if (maxSize && file.size > maxSize * 1024) {
      addToast('error', tMsg('file_too_large', `File "${file.name}" exceeds the maximum size of ${formatSize(maxSize)}.`))
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
        className={`martis-dropzone relative rounded-md border transition-colors ${dragOver ? 'border-indigo-500 bg-indigo-500/10' : ''}`}
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
              <span className="truncate text-sm" style={{ color: 'var(--martis-text)' }}>
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
            className="flex w-full flex-col items-center gap-2 px-4 py-6 text-sm"
            style={{ color: 'var(--martis-text-muted)' }}
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
          accept={acceptAttr}
          disabled={field.readonly}
          className="sr-only"
          onChange={(e) => {
            const file = e.target.files?.[0]
            if (file) handleFile(file)
          }}
        />
      </div>

      {showFileInfo !== false && (
        <>
          {maxSize && (
            <span className="text-xs" style={{ color: 'var(--martis-text-muted)' }}>
              Max: {formatSize(maxSize)}
            </span>
          )}
          {acceptedTypes && acceptedTypes.length > 0 && (
            <span className="text-xs" style={{ color: 'var(--martis-text-muted)' }}>
              Accepted: {acceptedTypes.map(t => `.${t}`).join(', ')}
            </span>
          )}
        </>
      )}
      {error && <small className="text-red-500">{error}</small>}
    </div>
  )
}

// ---------------------------------------------------------------------------
// Input — Multiple mode
// ---------------------------------------------------------------------------

/** Internal representation for each item in the multiple image list. */
interface MultiImageItem {
  id: string
  file?: globalThis.File
  existing?: ImageValue
  previewUrl: string
}

function MultipleImageInput({ field, value, onChange, error }: FieldInputProps) {
  const { t: tRes } = useTranslation("resources")
  const { t: tMsg } = useTranslation("messages")
  const inputRef = useRef<HTMLInputElement>(null)
  const [dragOver, setDragOver] = useState(false)
  const { addToast } = useToastSafe()

  const maxSize = (field as unknown as Record<string, unknown>).maxSize as number | undefined
  const acceptedTypes = (field as unknown as Record<string, unknown>).acceptedTypes as string[] | undefined
  const showFileInfo = (field as unknown as Record<string, unknown>).showFileInfo as boolean | undefined

  const acceptAttr = useMemo(() => {
    if (acceptedTypes && acceptedTypes.length > 0) {
      return acceptedTypes.map(t => `.${t}`).join(',')
    }
    return 'image/*'
  }, [acceptedTypes])

  function formatSize(kb: number): string {
    return kb >= 1024 ? `${(kb / 1024).toFixed(0)} MB` : `${kb} KB`
  }

  // Parse current value into items
  const items: MultiImageItem[] = useMemo(() => {
    const raw = value as { items?: MultiImageItem[] } | null
    if (raw && 'items' in raw && Array.isArray(raw.items)) {
      return raw.items
    }
    if (isImageValueArray(value)) {
      return value.map((iv, i) => ({
        id: `existing-${i}`,
        existing: iv,
        previewUrl: iv.thumbnailUrl ?? iv.url,
      }))
    }
    return []
  }, [value])

  const emitChange = useCallback((newItems: MultiImageItem[]) => {
    onChange({ items: newItems, __multiple: true })
  }, [onChange])

  function handleFiles(files: FileList | globalThis.File[]) {
    const newItems = [...items]
    for (const file of Array.from(files)) {
      if (acceptedTypes && acceptedTypes.length > 0) {
        const ext = file.name.split('.').pop()?.toLowerCase() ?? ''
        if (!acceptedTypes.includes(ext)) {
          addToast('error', tMsg('file_type_not_allowed', `Image type .${ext} is not allowed. Accepted: ${acceptedTypes.join(', ')}`))
          continue
        }
      } else if (!file.type.startsWith('image/')) {
        addToast('error', tMsg('file_not_image', `"${file.name}" is not a valid image file.`))
        continue
      }
      if (maxSize && file.size > maxSize * 1024) {
        addToast('error', tMsg('file_too_large', `File "${file.name}" exceeds the maximum size of ${formatSize(maxSize)}.`))
        continue
      }
      newItems.push({
        id: `new-${Date.now()}-${Math.random()}`,
        file,
        previewUrl: URL.createObjectURL(file),
      })
    }
    emitChange(newItems)
  }

  function handleRemove(id: string) {
    emitChange(items.filter((it) => it.id !== id))
  }

  function handleDrop(e: React.DragEvent) {
    e.preventDefault()
    setDragOver(false)
    if (e.dataTransfer.files.length > 0) handleFiles(e.dataTransfer.files)
  }

  return (
    <div className="flex flex-col gap-2">
      {/* Image grid */}
      {items.length > 0 && (
        <div className="flex flex-wrap gap-2">
          {items.map((item) => (
            <div
              key={item.id}
              className="group relative h-24 w-24 overflow-hidden rounded-md border"
              style={{ borderColor: 'var(--martis-border)' }}
            >
              <img
                src={item.previewUrl}
                alt={item.file?.name ?? item.existing?.name ?? ''}
                className="h-full w-full object-cover"
              />
              <button
                type="button"
                onClick={() => handleRemove(item.id)}
                className="absolute right-1 top-1 rounded-full bg-black/60 p-1 opacity-0 transition-opacity group-hover:opacity-100"
              >
                <TrashIcon size={12} className="text-white" />
              </button>
            </div>
          ))}
        </div>
      )}

      {/* Drop zone / add button */}
      <div
        className={`martis-dropzone relative rounded-md border transition-colors ${dragOver ? 'border-indigo-500 bg-indigo-500/10' : ''}`}
        style={{
          backgroundColor: 'var(--martis-input-bg)',
          borderColor: error ? '#ef4444' : 'var(--martis-border)',
        }}
        onDragOver={(e) => { e.preventDefault(); setDragOver(true) }}
        onDragLeave={() => setDragOver(false)}
        onDrop={handleDrop}
      >
        <button
          type="button"
          onClick={() => inputRef.current?.click()}
          className="flex w-full flex-col items-center gap-2 px-4 py-4 text-sm"
          style={{ color: 'var(--martis-text-muted)' }}
        >
          {items.length > 0 ? <PlusIcon size={24} /> : <ImageIcon size={28} />}
          <span>{items.length > 0 ? tRes('add_more_images') : tRes('choose_images')}</span>
        </button>

        <input
          ref={inputRef}
          id={field.attribute}
          name={field.attribute}
          type="file"
          accept={acceptAttr}
          multiple
          disabled={field.readonly}
          className="sr-only"
          onChange={(e) => {
            if (e.target.files && e.target.files.length > 0) {
              handleFiles(e.target.files)
              e.target.value = ''
            }
          }}
        />
      </div>

      {showFileInfo !== false && (
        <>
          {maxSize && (
            <span className="text-xs" style={{ color: 'var(--martis-text-muted)' }}>
              Max per image: {formatSize(maxSize)}
            </span>
          )}
          {acceptedTypes && acceptedTypes.length > 0 && (
            <span className="text-xs" style={{ color: 'var(--martis-text-muted)' }}>
              Accepted: {acceptedTypes.map(t => `.${t}`).join(', ')}
            </span>
          )}
        </>
      )}
      {error && <small className="text-red-500">{error}</small>}
    </div>
  )
}

// ---------------------------------------------------------------------------
// Exported input wrapper
// ---------------------------------------------------------------------------

export function ImageFieldInput(props: FieldInputProps) {
  const multiple = (props.field as unknown as Record<string, unknown>).multiple as boolean | undefined
  return multiple ? <MultipleImageInput {...props} /> : <SingleImageInput {...props} />
}
