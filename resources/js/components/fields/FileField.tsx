import { useTranslation } from "react-i18next"
import { useState, useRef, useCallback } from 'react'
import type { FieldDisplayProps, FieldInputProps } from './types'
import { FileIcon, FilePdfIcon, FileDocIcon, FileTextIcon, FileZipIcon, FileXlsIcon, FilePptIcon, FileCodeIcon, FileCsvIcon, DownloadSimpleIcon, TrashIcon, UploadSimpleIcon, PlusIcon } from '@phosphor-icons/react'
import { useToastSafe } from "@/contexts/ToastContext"

interface FileValue {
  path: string
  url: string
  name: string
}

function isFileValue(v: unknown): v is FileValue {
  return v !== null && typeof v === 'object' && 'url' in (v as Record<string, unknown>)
}

function isFileValueArray(v: unknown): v is FileValue[] {
  return Array.isArray(v) && v.every(isFileValue)
}

/**
 * Return the best Phosphor icon for a given filename extension.
 */
function getFileTypeIcon(filename: string, size: number) {
  const ext = filename.split('.').pop()?.toLowerCase() ?? ''
  switch (ext) {
    case 'pdf':
      return <FilePdfIcon size={size} style={{ color: 'var(--martis-danger)' }} />
    case 'doc':
    case 'docx':
      return <FileDocIcon size={size} style={{ color: 'var(--martis-info)' }} />
    case 'xls':
    case 'xlsx':
      return <FileXlsIcon size={size} style={{ color: 'var(--martis-success)' }} />
    case 'ppt':
    case 'pptx':
      return <FilePptIcon size={size} style={{ color: 'var(--martis-file-icon-ppt)' }} />
    case 'zip':
    case 'rar':
    case 'gz':
    case '7z':
    case 'tar':
      return <FileZipIcon size={size} style={{ color: 'var(--martis-file-icon-zip)' }} />
    case 'csv':
      return <FileCsvIcon size={size} style={{ color: 'var(--martis-success)' }} />
    case 'txt':
    case 'md':
    case 'rtf':
      return <FileTextIcon size={size} style={{ color: 'var(--martis-text-muted)' }} />
    case 'js':
    case 'ts':
    case 'jsx':
    case 'tsx':
    case 'php':
    case 'py':
    case 'html':
    case 'css':
    case 'json':
    case 'xml':
    case 'yaml':
    case 'yml':
      return <FileCodeIcon size={size} style={{ color: 'var(--martis-accent)' }} />
    default:
      return <FileIcon size={size} style={{ color: 'var(--martis-text-muted)' }} />
  }
}

// ---------------------------------------------------------------------------
// Display
// ---------------------------------------------------------------------------

export function FileFieldDisplay({ value, field }: FieldDisplayProps) {
  const multiple = (field as unknown as Record<string, unknown>).multiple as boolean | undefined

  if (multiple && isFileValueArray(value)) {
    if (value.length === 0) return <span className="martis-text-muted">—</span>
    return (
      <div className="flex flex-col gap-1">
        {value.map((file) => (
          <a
            key={file.path}
            href={file.url}
            target="_blank"
            rel="noopener noreferrer"
            className="inline-flex items-center gap-2 text-sm hover:underline"
            style={{ color: 'var(--martis-accent)' }}
          >
            {getFileTypeIcon(file.name, 16)}
            {file.name}
            <DownloadSimpleIcon size={14} />
          </a>
        ))}
      </div>
    )
  }

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
      {getFileTypeIcon(value.name, 16)}
      {value.name}
      <DownloadSimpleIcon size={14} />
    </a>
  )
}

// ---------------------------------------------------------------------------
// Input — Single mode
// ---------------------------------------------------------------------------

function SingleFileInput({ field, value, onChange, error }: FieldInputProps) {
  const inputRef = useRef<HTMLInputElement>(null)
  const [dragOver, setDragOver] = useState(false)
  const { addToast } = useToastSafe()
  const { t: tMsg } = useTranslation("messages")
  const { t: tRes } = useTranslation("resources")

  const currentFile = value instanceof window.File ? value : null
  const existingFile = isFileValue(value) ? value : null
  const hasValue = currentFile !== null || existingFile !== null

  const acceptedTypes = (field as unknown as Record<string, unknown>).acceptedTypes as string[] | undefined
  const maxSize = (field as unknown as Record<string, unknown>).maxSize as number | undefined
  const showFileInfo = (field as unknown as Record<string, unknown>).showFileInfo as boolean | undefined

  const accept = acceptedTypes?.length
    ? acceptedTypes.map((t) => `.${t}`).join(',')
    : undefined

  function formatSize(kb: number): string {
    return kb >= 1024 ? `${(kb / 1024).toFixed(0)} MB` : `${kb} KB`
  }

  function handleFile(file: globalThis.File) {
    if (acceptedTypes && acceptedTypes.length > 0) {
      const ext = file.name.split('.').pop()?.toLowerCase() ?? ''
      if (!acceptedTypes.includes(ext)) {
        addToast('error', tMsg('file_type_not_allowed', `File type .${ext} is not allowed. Accepted: ${acceptedTypes.join(', ')}`))
        return
      }
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

  const displayName = currentFile ? currentFile.name : existingFile?.name ?? ''

  return (
    <div className="flex flex-col gap-1">
      <div
        className="martis-dropzone relative flex items-center gap-3 rounded-md border px-4 py-3 transition-colors"
        style={{
          backgroundColor: dragOver ? 'color-mix(in srgb, var(--martis-accent) 10%, transparent)' : 'var(--martis-input-bg)',
          borderColor: dragOver ? 'var(--martis-accent)' : (error ? 'var(--martis-danger)' : 'var(--martis-border)'),
        }}
        onDragOver={(e) => { e.preventDefault(); setDragOver(true) }}
        onDragLeave={() => setDragOver(false)}
        onDrop={handleDrop}
      >
        {hasValue ? (
          <>
            {getFileTypeIcon(displayName, 20)}
            <span className="flex-1 truncate text-sm" style={{ color: 'var(--martis-text)' }}>
              {displayName}
            </span>
            {existingFile && (
              <a
                href={existingFile.url}
                target="_blank"
                rel="noopener noreferrer"
                className="flex-shrink-0 rounded p-1 hover:opacity-70"
                style={{ color: 'var(--martis-accent)' }}
              >
                <DownloadSimpleIcon size={16} />
              </a>
            )}
            <button
              type="button"
              onClick={handleClear}
              className="flex-shrink-0 rounded p-1 transition-colors"
              style={{ color: 'var(--martis-danger)' }}
              onMouseEnter={(e) => (e.currentTarget.style.backgroundColor = 'color-mix(in srgb, var(--martis-danger) 10%, transparent)')}
              onMouseLeave={(e) => (e.currentTarget.style.backgroundColor = 'transparent')}
            >
              <TrashIcon size={16} />
            </button>
          </>
        ) : (
          <button
            type="button"
            onClick={() => inputRef.current?.click()}
            className="flex w-full items-center gap-2 text-sm"
            style={{ color: 'var(--martis-text-muted)' }}
          >
            <UploadSimpleIcon size={20} />
            <span>{tRes('choose_files')}</span>
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

/** Internal representation for each item in the multiple list. */
interface MultiFileItem {
  id: string
  file?: globalThis.File    // new upload
  existing?: FileValue       // existing server file
}

function MultipleFileInput({ field, value, onChange, error }: FieldInputProps) {
  const { t: tRes } = useTranslation("resources")
  const { t: tMsg } = useTranslation("messages")
  const inputRef = useRef<HTMLInputElement>(null)
  const [dragOver, setDragOver] = useState(false)
  const { addToast } = useToastSafe()

  const acceptedTypes = (field as unknown as Record<string, unknown>).acceptedTypes as string[] | undefined
  const maxSize = (field as unknown as Record<string, unknown>).maxSize as number | undefined
  const showFileInfo = (field as unknown as Record<string, unknown>).showFileInfo as boolean | undefined

  const accept = acceptedTypes?.length
    ? acceptedTypes.map((t) => `.${t}`).join(',')
    : undefined

  function formatSize(kb: number): string {
    return kb >= 1024 ? `${(kb / 1024).toFixed(0)} MB` : `${kb} KB`
  }

  // Parse current value into items
  const items: MultiFileItem[] = (() => {
    const raw = value as { items?: MultiFileItem[] } | null
    if (raw && 'items' in raw && Array.isArray(raw.items)) {
      return raw.items
    }
    // Initial load from server: array of FileValue
    if (isFileValueArray(value)) {
      return value.map((fv, i) => ({ id: `existing-${i}`, existing: fv }))
    }
    return []
  })()

  const emitChange = useCallback((newItems: MultiFileItem[]) => {
    onChange({ items: newItems, __multiple: true })
  }, [onChange])

  function handleFiles(files: FileList | globalThis.File[]) {
    const newItems = [...items]
    for (const file of Array.from(files)) {
      if (acceptedTypes && acceptedTypes.length > 0) {
        const ext = file.name.split('.').pop()?.toLowerCase() ?? ''
        if (!acceptedTypes.includes(ext)) {
          addToast('error', tMsg('file_type_not_allowed', `File type .${ext} is not allowed. Accepted: ${acceptedTypes.join(', ')}`))
          continue
        }
      }
      if (maxSize && file.size > maxSize * 1024) {
        addToast('error', tMsg('file_too_large', `File "${file.name}" exceeds the maximum size of ${formatSize(maxSize)}.`))
        continue
      }
      newItems.push({ id: `new-${Date.now()}-${Math.random()}`, file })
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
      {/* File list */}
      {items.length > 0 && (
        <div className="flex flex-col gap-1">
          {items.map((item) => {
            const name = item.file?.name ?? item.existing?.name ?? tRes('unknown_file', 'Unknown')
            return (
              <div
                key={item.id}
                className="flex items-center gap-2 rounded-md border px-3 py-2"
                style={{
                  backgroundColor: 'var(--martis-input-bg)',
                  borderColor: 'var(--martis-border)',
                }}
              >
                {getFileTypeIcon(name, 16)}
                <span className="flex-1 truncate text-sm" style={{ color: 'var(--martis-text)' }}>{name}</span>
                {item.existing && (
                  <a
                    href={item.existing.url}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="flex-shrink-0 rounded p-1 hover:opacity-70"
                    style={{ color: 'var(--martis-accent)' }}
                  >
                    <DownloadSimpleIcon size={14} />
                  </a>
                )}
                <button
                  type="button"
                  onClick={() => handleRemove(item.id)}
                  className="flex-shrink-0 rounded p-1 transition-colors"
                  style={{ color: 'var(--martis-danger)' }}
                  onMouseEnter={(e) => (e.currentTarget.style.backgroundColor = 'color-mix(in srgb, var(--martis-danger) 10%, transparent)')}
                  onMouseLeave={(e) => (e.currentTarget.style.backgroundColor = 'transparent')}
                >
                  <TrashIcon size={14} />
                </button>
              </div>
            )
          })}
        </div>
      )}

      {/* Drop zone / add button */}
      <div
        className="martis-dropzone relative flex items-center gap-3 rounded-md border px-4 py-3 transition-colors"
        style={{
          backgroundColor: dragOver ? 'color-mix(in srgb, var(--martis-accent) 10%, transparent)' : 'var(--martis-input-bg)',
          borderColor: dragOver ? 'var(--martis-accent)' : (error ? 'var(--martis-danger)' : 'var(--martis-border)'),
        }}
        onDragOver={(e) => { e.preventDefault(); setDragOver(true) }}
        onDragLeave={() => setDragOver(false)}
        onDrop={handleDrop}
      >
        <button
          type="button"
          onClick={() => inputRef.current?.click()}
          className="flex w-full items-center gap-2 text-sm"
          style={{ color: 'var(--martis-text-muted)' }}
        >
          {items.length > 0 ? <PlusIcon size={20} /> : <UploadSimpleIcon size={20} />}
          <span>{items.length > 0 ? tRes('add_more_files') : tRes('choose_files')}</span>
        </button>

        <input
          ref={inputRef}
          id={field.attribute}
          name={field.attribute}
          type="file"
          accept={accept}
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
              Max per file: {formatSize(maxSize)}
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

export function FileFieldInput(props: FieldInputProps) {
  const multiple = (props.field as unknown as Record<string, unknown>).multiple as boolean | undefined
  return multiple ? <MultipleFileInput {...props} /> : <SingleFileInput {...props} />
}
