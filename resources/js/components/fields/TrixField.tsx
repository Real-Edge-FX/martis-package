import { useState, useEffect, useRef, useCallback } from 'react'
import type { FieldDisplayProps, FieldInputProps } from './types'
import { BASE_PATH } from '@/lib/config'
import 'trix/dist/trix.css'
import 'trix'

export function TrixFieldDisplay({ field, value }: FieldDisplayProps) {
  if (value === null || value === undefined || value === '') {
    return <span className="text-gray-400 dark:text-gray-500">&mdash;</span>
  }

  const alwaysShow = (field as Record<string, unknown>).alwaysShow as boolean ?? false
  const [expanded, setExpanded] = useState(alwaysShow)

  if (!expanded) {
    return (
      <button
        type="button"
        onClick={() => setExpanded(true)}
        className="text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300 underline text-sm"
      >
        Show Content
      </button>
    )
  }

  return (
    <div className="relative">
      {!alwaysShow && (
        <button
          type="button"
          onClick={() => setExpanded(false)}
          className="text-xs text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300 underline mb-2"
        >
          Hide Content
        </button>
      )}
      <div
        className="prose dark:prose-invert max-w-none text-sm trix-content"
        dangerouslySetInnerHTML={{ __html: String(value) }}
      />
    </div>
  )
}

export function TrixFieldInput({ field, value, onChange, error }: FieldInputProps) {
  const containerRef = useRef<HTMLDivElement>(null)
  const editorRef = useRef<HTMLElement | null>(null)
  const hiddenInputRef = useRef<HTMLInputElement | null>(null)
  const inputId = `trix-input-${field.attribute}`
  const initialized = useRef(false)
  const lastPropValue = useRef<string>('')
  const internalUpdate = useRef(false)

  const currentValue = value === null || value === undefined ? '' : String(value)

  const handleChange = useCallback(() => {
    if (hiddenInputRef.current) {
      internalUpdate.current = true
      onChange(hiddenInputRef.current.value)
    }
  }, [onChange])

  // Initialize Trix editor once
  useEffect(() => {
    if (!containerRef.current || initialized.current) return

    const input = document.createElement('input')
    input.id = inputId
    input.type = 'hidden'
    input.value = currentValue
    containerRef.current.appendChild(input)
    hiddenInputRef.current = input
    lastPropValue.current = currentValue

    const editor = document.createElement('trix-editor')
    editor.setAttribute('input', inputId)
    editor.classList.add('trix-content')
    if (field.readonly) {
      editor.setAttribute('contenteditable', 'false')
    }
    containerRef.current.appendChild(editor)
    editorRef.current = editor

    editor.addEventListener('trix-change', handleChange)

    // Handle file attachments
    const withFiles = (field as Record<string, unknown>).withFiles
    editor.addEventListener('trix-attachment-add', ((event: Event) => {
      const attachment = (event as unknown as { attachment: { file: File | null; remove: () => void; setAttributes: (attrs: Record<string, string>) => void } }).attachment
      if (!attachment.file) return

      if (!withFiles) {
        attachment.remove()
        return
      }

      const formData = new FormData()
      formData.append('file', attachment.file)

      const csrfMatch = document.cookie.match(/XSRF-TOKEN=([^;]+)/)
      const headers: Record<string, string> = {
        'X-Requested-With': 'XMLHttpRequest',
        'Accept': 'application/json',
      }
      if (csrfMatch) {
        headers['X-XSRF-TOKEN'] = decodeURIComponent(csrfMatch[1])
      }

      fetch(`${BASE_PATH}/api/attachments/upload`, {
        method: 'POST',
        body: formData,
        credentials: 'same-origin',
        headers,
      })
        .then((response) => {
          if (!response.ok) throw new Error('Upload failed')
          return response.json()
        })
        .then((data: { url: string; href?: string }) => {
          attachment.setAttributes({
            url: data.url,
            href: data.href || data.url,
          })
        })
        .catch(() => {
          attachment.remove()
        })
    }) as EventListener)

    initialized.current = true

    return () => {
      editor.removeEventListener('trix-change', handleChange)
    }
  }, [])

  // Sync external value changes into the editor (record loading on edit)
  useEffect(() => {
    if (!initialized.current || !editorRef.current) return

    if (internalUpdate.current) {
      internalUpdate.current = false
      lastPropValue.current = currentValue
      return
    }

    if (currentValue !== lastPropValue.current) {
      lastPropValue.current = currentValue
      const trixEditor = editorRef.current as HTMLElement & { editor?: { loadHTML: (html: string) => void } }
      if (trixEditor.editor) {
        trixEditor.editor.loadHTML(currentValue)
      }
      if (hiddenInputRef.current) {
        hiddenInputRef.current.value = currentValue
      }
    }
  }, [currentValue])

  return (
    <div className="flex flex-col gap-1">
      <div
        ref={containerRef}
        className="martis-trix-wrapper"
      />
      {error && <small className="text-red-500">{error}</small>}
    </div>
  )
}
