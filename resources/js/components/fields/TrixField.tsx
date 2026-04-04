import { useState, useEffect, useRef } from 'react'
import type { FieldDisplayProps, FieldInputProps } from './types'
import 'trix/dist/trix.css'
import 'trix'

export function TrixFieldDisplay({ field, value }: FieldDisplayProps) {
  if (value === null || value === undefined || value === '') {
    return <span className="text-gray-400 dark:text-gray-500">—</span>
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
  const editorRef = useRef<HTMLDivElement>(null)
  const trixRef = useRef<HTMLElement | null>(null)
  const inputId = `trix-input-${field.attribute}`
  const initialized = useRef(false)

  useEffect(() => {
    if (!editorRef.current || initialized.current) return

    // Create hidden input for trix
    const input = document.createElement('input')
    input.id = inputId
    input.type = 'hidden'
    input.value = value === null || value === undefined ? '' : String(value)
    editorRef.current.appendChild(input)

    // Create trix-editor element
    const editor = document.createElement('trix-editor')
    editor.setAttribute('input', inputId)
    editor.classList.add('trix-content')
    if (field.readonly) {
      editor.setAttribute('contenteditable', 'false')
    }
    editorRef.current.appendChild(editor)
    trixRef.current = editor

    // Listen for changes
    const handleChange = () => {
      onChange(input.value)
    }
    editor.addEventListener('trix-change', handleChange)

    initialized.current = true

    return () => {
      editor.removeEventListener('trix-change', handleChange)
    }
  }, [])

  return (
    <div className="flex flex-col gap-1">
      <div
        ref={editorRef}
        className="border border-gray-300 dark:border-gray-600 rounded overflow-hidden"
      />
      {error && <small className="text-red-500">{error}</small>}
    </div>
  )
}
