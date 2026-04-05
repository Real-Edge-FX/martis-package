import { useState, useMemo } from 'react'
import type { FieldDisplayProps, FieldInputProps } from './types'
import { marked } from 'marked'
import { Eye, EyeSlash } from '@phosphor-icons/react'
import { useTranslation } from 'react-i18next'

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
  const { t } = useTranslation('messages')
  if (value === null || value === undefined || value === '') {
    return <span className="martis-text-muted">—</span>
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
        className="inline-flex items-center gap-1.5 text-sm" style={{ color: "var(--martis-accent)" }}
      >
        <Eye size={16} weight="bold" />
        {t('show_content')}
      </button>
    )
  }

  return (
    <div className="relative">
      {!alwaysShow && (
        <button
          type="button"
          onClick={() => setExpanded(false)}
          className="inline-flex items-center gap-1.5 text-sm mb-2" style={{ color: "var(--martis-accent)" }}
        >
          <EyeSlash size={16} weight="bold" />
          {t('hide')}
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
  const { t } = useTranslation('messages')
  const preset = (field as Record<string, unknown>).preset as string ?? 'default'
  const [showPreview, setShowPreview] = useState(false)
  const text = value === null || value === undefined ? '' : String(value)
  const html = useMemo(() => renderMarkdown(text, preset), [text, preset])

  return (
    <div className="flex flex-col gap-1">
      <div className="rounded overflow-hidden" style={{ border: "1px solid var(--martis-border)" }}>
        <div className="flex" style={{ borderBottom: "1px solid var(--martis-border)", backgroundColor: "var(--martis-surface-alt)" }}>
          <button
            type="button"
            onClick={() => setShowPreview(false)}
            className="px-3 py-1.5 text-xs font-medium" style={{ color: !showPreview ? 'var(--martis-accent)' : 'var(--martis-text-muted)', borderBottom: !showPreview ? '2px solid var(--martis-accent)' : 'none' }}
          >
            {t('write')}
          </button>
          <button
            type="button"
            onClick={() => setShowPreview(true)}
            className="px-3 py-1.5 text-xs font-medium" style={{ color: showPreview ? 'var(--martis-accent)' : 'var(--martis-text-muted)', borderBottom: showPreview ? '2px solid var(--martis-accent)' : 'none' }}
          >
            {t('preview')}
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
            placeholder={field.placeholder ?? t('write_markdown_placeholder')}
            className="w-full p-3 text-sm font-mono resize-y focus:outline-none min-h-[200px]" style={{ backgroundColor: "var(--martis-input-bg)", color: "var(--martis-text)" }}
          />
        )}
      </div>
      {error && <small className="text-red-500">{error}</small>}
    </div>
  )
}
