import { useState, useMemo } from 'react'
import type { FieldDisplayProps, FieldInputProps } from './types'
import { marked } from 'marked'
import { Eye, EyeSlash } from '@phosphor-icons/react'

/**
 * Safely render Markdown to HTML using the configured preset approach.
 * Presets: 'default' (GFM), 'commonmark', 'zero' (no formatting).
 */
function renderMarkdown(content: string, preset: string): string {
  if (preset === 'zero') {
    return content
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/\n/g, '<br>')
  }

  marked.setOptions({
    gfm: preset !== 'commonmark',
    breaks: preset === 'default',
  })

  return marked.parse(content, { async: false }) as string
}

export function MarkdownFieldDisplay({ field, value }: FieldDisplayProps) {
  if (value === null || value === undefined || value === '') {
    return <span className="text-gray-400 dark:text-gray-500">—</span>
  }

  const alwaysShow = (field as Record<string, unknown>).alwaysShow as boolean ?? false
  const preset = (field as Record<string, unknown>).preset as string ?? 'default'
  const [expanded, setExpanded] = useState(alwaysShow)
  const html = useMemo(() => renderMarkdown(String(value), preset), [value, preset])

  if (!expanded) {
    return (
      <button
        type="button"
        onClick={() => setExpanded(true)}
        className="inline-flex items-center gap-1.5 text-sm text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300"
      >
        <Eye size={16} weight="bold" />
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
          className="inline-flex items-center gap-1.5 text-sm text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300 mb-2"
        >
          <EyeSlash size={16} weight="bold" />
          Hide
        </button>
      )}
      <div
        className="prose dark:prose-invert max-w-none text-sm"
        dangerouslySetInnerHTML={{ __html: html }}
      />
    </div>
  )
}

export function MarkdownFieldInput({ field, value, onChange, error }: FieldInputProps) {
  const preset = (field as Record<string, unknown>).preset as string ?? 'default'
  const [showPreview, setShowPreview] = useState(false)
  const text = value === null || value === undefined ? '' : String(value)
  const html = useMemo(() => renderMarkdown(text, preset), [text, preset])

  return (
    <div className="flex flex-col gap-1">
      <div className="border border-gray-300 dark:border-gray-600 rounded overflow-hidden">
        <div className="flex border-b border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800">
          <button
            type="button"
            onClick={() => setShowPreview(false)}
            className={`px-3 py-1.5 text-xs font-medium ${!showPreview ? 'text-blue-600 border-b-2 border-blue-600 dark:text-blue-400' : 'text-gray-500 dark:text-gray-400'}`}
          >
            Write
          </button>
          <button
            type="button"
            onClick={() => setShowPreview(true)}
            className={`px-3 py-1.5 text-xs font-medium ${showPreview ? 'text-blue-600 border-b-2 border-blue-600 dark:text-blue-400' : 'text-gray-500 dark:text-gray-400'}`}
          >
            Preview
          </button>
        </div>
        {showPreview ? (
          <div
            className="prose dark:prose-invert max-w-none text-sm p-3 min-h-[200px]"
            dangerouslySetInnerHTML={{ __html: html }}
          />
        ) : (
          <textarea
            id={field.attribute}
            name={field.attribute}
            value={text}
            readOnly={field.readonly}
            disabled={field.readonly}
            onChange={(e) => onChange(e.target.value)}
            rows={10}
            placeholder={field.placeholder ?? 'Write your markdown here...'}
            className="w-full p-3 text-sm font-mono bg-white dark:bg-gray-900 text-gray-900 dark:text-white resize-y focus:outline-none min-h-[200px]"
          />
        )}
      </div>
      {error && <small className="text-red-500">{error}</small>}
    </div>
  )
}
