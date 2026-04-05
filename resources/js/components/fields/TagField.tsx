import { useState, useEffect, useRef, useCallback } from 'react'
import { useParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { MagnifyingGlass, X, Plus, Check } from '@phosphor-icons/react'
import { api } from '@/lib/api'
import type { FieldDisplayProps, FieldInputProps } from './types'
import type { PaginatedResponse } from '@/types'

interface TagValue {
  id: number | string
  title?: string | null
}

interface RelatedRecord {
  id: number | string
  _title?: string
  [key: string]: unknown
}

function isTagValue(v: unknown): v is TagValue {
  return v !== null && typeof v === 'object' && 'id' in (v as Record<string, unknown>)
}

function toTagArray(value: unknown): TagValue[] {
  if (!value) return []
  if (Array.isArray(value)) {
    return value.filter(isTagValue)
  }
  return []
}

// ---------------------------------------------------------------------------
// Display
// ---------------------------------------------------------------------------

export function TagFieldDisplay({ field, value }: FieldDisplayProps) {
  const tags = toTagArray(value)
  const displayAsList = (field as Record<string, unknown>).displayAsList as boolean | undefined

  if (tags.length === 0) {
    return <span className="martis-text-muted">—</span>
  }

  if (displayAsList) {
    return (
      <ul className="flex flex-col gap-0.5 text-sm" style={{ color: 'var(--martis-text)' }}>
        {tags.map((tag) => (
          <li key={tag.id} className="flex items-center gap-1">
            <span
              className="w-1.5 h-1.5 rounded-full shrink-0"
              style={{ backgroundColor: 'var(--martis-accent)' }}
            />
            {tag.title ?? String(tag.id)}
          </li>
        ))}
      </ul>
    )
  }

  return (
    <div className="flex flex-wrap gap-1">
      {tags.map((tag) => (
        <span
          key={tag.id}
          className="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium"
          style={{
            backgroundColor: 'var(--martis-badge-info-bg)',
            color: 'var(--martis-badge-info-text)',
            border: '1px solid var(--martis-badge-info-border)',
          }}
        >
          {tag.title ?? String(tag.id)}
        </span>
      ))}
    </div>
  )
}

// ---------------------------------------------------------------------------
// Input — relational tag selector
// ---------------------------------------------------------------------------

export function TagFieldInput({ field, value, onChange, error }: FieldInputProps) {
  const { t: tMsg } = useTranslation('messages')
  const relatedResource = (field as Record<string, unknown>).relatedResource as string | undefined
  const titleAttribute = (field as Record<string, unknown>).titleAttribute as string | undefined
  const preload = (field as Record<string, unknown>).preload as boolean | undefined

  const [selected, setSelected] = useState<TagValue[]>(() => toTagArray(value))
  const [open, setOpen] = useState(false)
  const [search, setSearch] = useState('')
  const [options, setOptions] = useState<RelatedRecord[]>([])
  const [loading, setLoading] = useState(false)

  const containerRef = useRef<HTMLDivElement>(null)
  const debounceRef = useRef<ReturnType<typeof setTimeout> | null>(null)

  // Close on outside click
  useEffect(() => {
    function handleOutside(e: MouseEvent) {
      if (containerRef.current && !containerRef.current.contains(e.target as Node)) {
        setOpen(false)
        setSearch('')
      }
    }
    document.addEventListener('mousedown', handleOutside)
    return () => document.removeEventListener('mousedown', handleOutside)
  }, [])

  // Cleanup debounce timer on unmount
  useEffect(() => {
    return () => {
      if (debounceRef.current) clearTimeout(debounceRef.current)
    }
  }, [])

  // Get current resource context for relatable endpoint (REA-1144)
  const params = useParams<{ resource?: string; id?: string }>()
  const sourceResource = params.resource
  const sourceId = params.id ?? '_'

  const fetchOptions = useCallback(async (query: string) => {
    if (!relatedResource) return

    setLoading(true)
    try {
      const searchParam = query ? `&search=${encodeURIComponent(query)}` : ''
      // Always use relatable endpoint - applies query hooks server-side (REA-1144)
      const endpoint = sourceResource
        ? `/api/resources/${sourceResource}/${sourceId}/relatable/${field.attribute}?per_page=30${searchParam}`
        : `/api/resources/_/_/relatable/${field.attribute}?per_page=30&related_resource=${relatedResource}${searchParam}`
      const res = await api.get<PaginatedResponse<RelatedRecord>>(endpoint)
      setOptions(res.data ?? [])
    } catch {
      setOptions([])
    } finally {
      setLoading(false)
    }
  }, [relatedResource, sourceResource, sourceId, field.attribute])

  // Preload all options on mount if preload=true
  useEffect(() => {
    if (preload && relatedResource) {
      void fetchOptions('')
    }
  }, [preload, relatedResource, fetchOptions])

  // Load options when dropdown opens (if not preloaded)
  useEffect(() => {
    if (open && !preload) {
      void fetchOptions('')
    }
  }, [open, preload, fetchOptions])

  function handleSearchChange(query: string) {
    setSearch(query)
    if (debounceRef.current) clearTimeout(debounceRef.current)
    debounceRef.current = setTimeout(() => {
      void fetchOptions(query)
    }, 300)
  }

  function getOptionLabel(record: RelatedRecord): string {
    if (titleAttribute && record[titleAttribute] != null) {
      return String(record[titleAttribute])
    }
    if (record._title) return record._title
    for (const attr of ['name', 'title', 'label']) {
      if (record[attr] != null) return String(record[attr])
    }
    return `#${record.id}`
  }

  function isSelectedId(id: number | string): boolean {
    return selected.some((t) => String(t.id) === String(id))
  }

  function toggleTag(record: RelatedRecord) {
    if (field.readonly) return
    const label = getOptionLabel(record)
    let next: TagValue[]

    if (isSelectedId(record.id)) {
      next = selected.filter((t) => String(t.id) !== String(record.id))
    } else {
      next = [...selected, { id: record.id, title: label }]
    }

    setSelected(next)
    onChange(next)
  }

  function removeTag(id: number | string) {
    if (field.readonly) return
    const next = selected.filter((t) => String(t.id) !== String(id))
    setSelected(next)
    onChange(next)
  }

  return (
    <div ref={containerRef} className="flex flex-col gap-1 relative">
      {/* Selected tags */}
      {selected.length > 0 && (
        <div className="flex flex-wrap gap-1">
          {selected.map((tag) => (
            <span
              key={tag.id}
              className="inline-flex items-center gap-1 rounded px-2 py-0.5 text-xs font-medium"
              style={{
                backgroundColor: 'var(--martis-badge-info-bg)',
                color: 'var(--martis-badge-info-text)',
                border: '1px solid var(--martis-badge-info-border)',
              }}
            >
              {tag.title ?? String(tag.id)}
              {!field.readonly && (
                <button
                  type="button"
                  onClick={() => removeTag(tag.id)}
                  title={`Remove ${tag.title}`}
                  className="opacity-60 hover:opacity-100 transition-opacity"
                  style={{ color: 'var(--martis-badge-info-text)', lineHeight: 1 }}
                >
                  <X size={10} weight="bold" />
                </button>
              )}
            </span>
          ))}
        </div>
      )}

      {/* Add button / trigger */}
      {!field.readonly && (
        <button
          type="button"
          onClick={() => setOpen(!open)}
          className="flex items-center gap-1.5 text-xs font-medium transition-opacity hover:opacity-80"
          style={{ color: 'var(--martis-accent)' }}
        >
          <Plus size={12} weight="bold" />
          Add {field.label}
        </button>
      )}

      {/* Dropdown */}
      {open && !field.readonly && (
        <div
          className="absolute z-50 rounded-md shadow-lg"
          style={{
            top: '100%',
            left: 0,
            minWidth: '16rem',
            backgroundColor: 'var(--martis-surface)',
            border: '1px solid var(--martis-border)',
            maxHeight: '18rem',
            overflow: 'hidden',
            display: 'flex',
            flexDirection: 'column',
          }}
        >
          {/* Search input */}
          <div
            className="flex items-center gap-2 px-3 py-2"
            style={{ borderBottom: '1px solid var(--martis-border)' }}
          >
            <MagnifyingGlass size={14} style={{ color: 'var(--martis-text-muted)', flexShrink: 0 }} />
            <input
              autoFocus
              type="text"
              value={search}
              onChange={(e) => handleSearchChange(e.target.value)}
              placeholder={tMsg('search_tags')}
              style={{
                flex: 1,
                border: 'none',
                outline: 'none',
                background: 'transparent',
                fontSize: '0.875rem',
                color: 'var(--martis-text)',
              }}
            />
          </div>

          {/* Options */}
          <div style={{ overflowY: 'auto', flex: 1 }}>
            {loading && options.length === 0 ? (
              <div
                style={{
                  padding: '0.75rem',
                  textAlign: 'center',
                  fontSize: '0.75rem',
                  color: 'var(--martis-text-muted)',
                }}
              >
                {tMsg('loading')}
              </div>
            ) : options.length === 0 ? (
              <div
                style={{
                  padding: '0.75rem',
                  textAlign: 'center',
                  fontSize: '0.75rem',
                  color: 'var(--martis-text-muted)',
                }}
              >
                {search ? tMsg('no_results_found') : tMsg('no_tags_available')}
              </div>
            ) : (
              options.map((record) => {
                const label = getOptionLabel(record)
                const alreadySelected = isSelectedId(record.id)
                return (
                  <button
                    key={record.id}
                    type="button"
                    onClick={() => toggleTag(record)}
                    className="w-full text-left flex items-center justify-between transition-colors"
                    style={{
                      padding: '0.5rem 0.75rem',
                      fontSize: '0.875rem',
                      color: 'var(--martis-text)',
                      backgroundColor: alreadySelected ? 'var(--martis-surface-alt)' : 'transparent',
                    }}
                    onMouseEnter={(e) => { if (!alreadySelected) e.currentTarget.style.backgroundColor = 'var(--martis-hover)' }}
                    onMouseLeave={(e) => { e.currentTarget.style.backgroundColor = alreadySelected ? 'var(--martis-surface-alt)' : 'transparent' }}
                  >
                    <span>{label}</span>
                    {alreadySelected && (
                      <Check size={12} weight="bold" style={{ color: 'var(--martis-accent)' }} />
                    )}
                  </button>
                )
              })
            )}
          </div>
        </div>
      )}

      {error && <small className="text-red-500">{error}</small>}
    </div>
  )
}
