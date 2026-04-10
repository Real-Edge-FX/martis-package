import { useState, useEffect, useRef, useCallback, useMemo } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useParams } from 'react-router-dom'
import { api } from '@/lib/api'
import type { FieldDisplayProps, FieldInputProps } from './types'
import type { PaginatedResponse } from '@/types'
import { CaretDown, MagnifyingGlass, X, Check, Plus } from '@phosphor-icons/react'
import { InlineCreateModal } from '@/components/InlineCreateModal'
import { useQueryClient } from '@tanstack/react-query'

interface BelongsToValue {
  id: number | string
  title?: string | null
}

function isBelongsToValue(v: unknown): v is BelongsToValue {
  return v !== null && typeof v === 'object' && 'id' in (v as Record<string, unknown>)
}

// ---------------------------------------------------------------------------
// Display
// ---------------------------------------------------------------------------

export function BelongsToFieldDisplay({ value, field }: FieldDisplayProps) {
  if (value === null || value === undefined || value === '') {
    return <span className="martis-text-muted">—</span>
  }

  if (isBelongsToValue(value)) {
    const label = value.title ?? String(value.id)
    const relatedResource = (field as unknown as Record<string, unknown>).relatedResource as string | undefined

    const displayAsLink = (field as unknown as Record<string, unknown>).displayAsLink !== false

    if (relatedResource && displayAsLink) {
      return (
        <Link
          to={`/resources/${relatedResource}/${value.id}`}
          className="text-sm hover:underline"
          style={{ color: 'var(--martis-accent)' }}
        >
          {label}
        </Link>
      )
    }

    return <span className="martis-text">{label}</span>
  }

  return <span className="martis-text">{String(value)}</span>
}

// ---------------------------------------------------------------------------
// Input — Searchable dropdown (single select)
// ---------------------------------------------------------------------------

interface RelatedRecord {
  id: number | string
  _title?: string
  [key: string]: unknown
}

export function BelongsToFieldInput({ field, value, onChange, error, resourceKey, recordId }: FieldInputProps) {
  const { t: tMsg } = useTranslation('messages')
  const relatedResource = (field as unknown as Record<string, unknown>).relatedResource as string | undefined
  const titleAttribute = (field as unknown as Record<string, unknown>).titleAttribute as string | undefined
  const isNullable = (field as unknown as Record<string, unknown>).nullable as boolean | undefined
  const showCreateRelationButton = (field as unknown as Record<string, unknown>).showCreateRelationButton === true
  const fieldModalSize = ((field as unknown as Record<string, unknown>).modalSize as string) || '2xl'
  const canShowCreateButton = showCreateRelationButton && !!relatedResource

  // Extract current ID from value (handles both plain ID and {id, title} objects)
  const currentId = useMemo(() => {
    if (value === null || value === undefined || value === '') return null
    if (isBelongsToValue(value)) return value.id
    return value
  }, [value])

  const currentTitle = useMemo(() => {
    if (isBelongsToValue(value)) return value.title ?? null
    return null
  }, [value])

  const [open, setOpen] = useState(false)
  const [search, setSearch] = useState('')
  const [options, setOptions] = useState<RelatedRecord[]>([])
  const [loading, setLoading] = useState(false)
  const [showInlineCreate, setShowInlineCreate] = useState(false)
  const qc = useQueryClient()
  const [selectedLabel, setSelectedLabel] = useState<string | null>(currentTitle)

  const containerRef = useRef<HTMLDivElement>(null)
  const searchInputRef = useRef<HTMLInputElement>(null)
  const debounceRef = useRef<ReturnType<typeof setTimeout> | null>(null)

  // Update selectedLabel when currentTitle changes (e.g. on form init)
  useEffect(() => {
    if (currentTitle) {
      setSelectedLabel(currentTitle)
    }
  }, [currentTitle])

  // Close dropdown on outside click
  useEffect(() => {
    function handleClickOutside(e: MouseEvent) {
      if (containerRef.current && !containerRef.current.contains(e.target as Node)) {
        setOpen(false)
      }
    }
    document.addEventListener('mousedown', handleClickOutside)
    return () => document.removeEventListener('mousedown', handleClickOutside)
  }, [])

  // Focus search input when dropdown opens
  useEffect(() => {
    if (open && searchInputRef.current) {
      searchInputRef.current.focus()
    }
  }, [open])

  // Get current resource context for relatable endpoint.
  // Prefer explicit resourceKey/recordId props (correct when rendered in drawers from actions).
  // Fall back to useParams() for standard page contexts.
  const params = useParams<{ resource?: string; id?: string }>()
  const sourceResource = resourceKey ?? params.resource
  const sourceId = recordId != null ? String(recordId) : (params.id ?? '_')

  // Fetch options from relatable endpoint (applies relatableQuery hooks)
  const fetchOptions = useCallback(async (query: string) => {
    if (!relatedResource) return

    setLoading(true)
    try {
      const searchParam = query ? `&search=${encodeURIComponent(query)}` : ''
      // Always use relatable endpoint - applies query hooks server-side
      const endpoint = sourceResource
        ? `/api/resources/${sourceResource}/${sourceId}/relatable/${field.attribute}?per_page=20${searchParam}`
        : `/api/resources/_/_/relatable/${field.attribute}?per_page=20&related_resource=${relatedResource}${searchParam}`
      const res = await api.get<PaginatedResponse<RelatedRecord>>(endpoint)
      setOptions(res.data ?? [])
    } catch {
      setOptions([])
    } finally {
      setLoading(false)
    }
  }, [relatedResource, sourceResource, sourceId, field.attribute])

  // Load initial options when dropdown opens (search uses debounce separately)
  useEffect(() => {
    if (open) {
      void fetchOptions("")
    }
  }, [open])

  // Debounced search
  function handleSearchChange(query: string) {
    setSearch(query)
    if (debounceRef.current) clearTimeout(debounceRef.current)
    debounceRef.current = setTimeout(() => {
      void fetchOptions(query)
    }, 300)
  }

  function getOptionLabel(record: RelatedRecord): string {
    // Prioritize the BelongsTo field's titleAttribute over the resource's _title
    if (titleAttribute && record[titleAttribute] !== undefined && record[titleAttribute] !== null) {
      return String(record[titleAttribute])
    }
    // Fall back to resource title
    if (record._title) return record._title
    // Try common label attributes
    for (const attr of ['name', 'title', 'label', 'email']) {
      if (record[attr] !== undefined && record[attr] !== null) {
        return String(record[attr])
      }
    }
    return `#${record.id}`
  }














  function handleInlineCreated(record: { id: string | number; title: string | null }) {
    onChange(record.id)
    setSelectedLabel(record.title ?? String(record.id))
    setShowInlineCreate(false)
    void qc.invalidateQueries({ queryKey: ["relatable"] })
  }

  function handleSelect(record: RelatedRecord) {
    const label = getOptionLabel(record)
    onChange(record.id)
    setSelectedLabel(label)
    setOpen(false)
    setSearch('')
  }

  function handleClear(e: React.MouseEvent) {
    e.stopPropagation()
    e.preventDefault()
    onChange(null)
    setSelectedLabel(null)
    setSearch('')
  }

  // Fallback: if no related resource configured, show a simple number input
  if (!relatedResource) {
    return (
      <div>
        <input
          type="number"
          id={field.attribute}
          name={field.attribute}
          placeholder="ID"
          value={currentId === null ? '' : String(currentId)}
          readOnly={field.readonly}
          required={field.required}
          onChange={(e) => onChange(e.target.value === '' ? null : Number(e.target.value))}
          className="martis-input block w-full rounded-md border px-3 py-2 text-sm"
          style={{
            backgroundColor: 'var(--martis-input-bg)',
            borderColor: error ? '#ef4444' : 'var(--martis-border)',
            color: 'var(--martis-text)',
          }}
        />
        {error && <p className="mt-1 text-xs text-red-500">{error}</p>}
      </div>
    )
  }

  return (
    <div ref={containerRef} className="relative">
      {/* Trigger button + inline create button */}
      <div className="flex items-center gap-1">
      <button
        type="button"
        onClick={() => !field.readonly && setOpen(!open)}
        disabled={field.readonly}
        className="martis-belongs-to-trigger"
        style={{
          flex: 1,
          borderColor: error ? '#ef4444' : open ? 'var(--martis-accent)' : 'var(--martis-border)',
          opacity: field.readonly ? 0.6 : 1,
          cursor: field.readonly ? 'not-allowed' : 'pointer',
        }}
      >
        <span className="martis-belongs-to-trigger-label">
          {selectedLabel ?? (currentId !== null ? `#${currentId}` : (
            <span style={{ color: 'var(--martis-text-muted)' }}>
              {isNullable ? tMsg('select') : tMsg('select_field', { field: field.label })}
            </span>
          ))}
        </span>

        {currentId !== null && isNullable && !field.readonly && (
          <span
            role="button"
            tabIndex={-1}
            onClick={handleClear}
            onKeyDown={(e) => { if (e.key === 'Enter') handleClear(e as unknown as React.MouseEvent) }}
            className="martis-belongs-to-clear"
            title="Clear selection"
          >
            <X size={14} weight="bold" />
          </span>
        )}

        <CaretDown
          size={14}
          weight="bold"
          style={{ color: 'var(--martis-text-muted)', flexShrink: 0 }}
        />
      </button>
      {canShowCreateButton && (
        <button
          type="button"
          onClick={(e) => { e.stopPropagation(); setShowInlineCreate(true) }}
          className="inline-flex items-center justify-center rounded-md border text-sm font-medium transition-colors"
          style={{
            borderColor: 'var(--martis-border)',
            backgroundColor: 'var(--martis-surface)',
            color: 'var(--martis-primary)',
            height: '38px',
            width: '38px',
            flexShrink: 0,
          }}
          onMouseEnter={(e) => { e.currentTarget.style.backgroundColor = 'var(--martis-hover)' }}
          onMouseLeave={(e) => { e.currentTarget.style.backgroundColor = 'var(--martis-surface)' }}
          title="Create new"
        >
          <Plus size={16} weight="bold" />
        </button>
      )}
      </div>

      {/* Dropdown panel */}
      {open && (
        <div className="martis-belongs-to-dropdown">
          {/* Search input */}
          <div className="martis-belongs-to-search">
            <MagnifyingGlass size={14} style={{ color: 'var(--martis-text-muted)', flexShrink: 0 }} />
            <input
              ref={searchInputRef}
              type="text"
              value={search}
              onChange={(e) => handleSearchChange(e.target.value)}
              placeholder={tMsg('search')}
              className="martis-belongs-to-search-input"
            />
          </div>

          {/* Options list */}
          <div className="martis-belongs-to-options">
            {loading && options.length === 0 ? (
              <div className="martis-belongs-to-empty">{tMsg('loading')}</div>
            ) : !loading && options.length === 0 ? (
              <div className="martis-belongs-to-empty">
                {search ? tMsg('no_results_found') : tMsg('no_records_available')}
              </div>
            ) : (
              options.map((record) => {
                const label = getOptionLabel(record)
                const isSelected = currentId !== null && String(record.id) === String(currentId)
                return (
                  <button
                    key={record.id}
                    type="button"
                    onClick={() => handleSelect(record)}
                    className={`martis-belongs-to-option ${isSelected ? 'martis-belongs-to-option--selected' : ''}`}
                  >
                    <span className="martis-belongs-to-option-label">{label}</span>
                    {isSelected && (
                      <Check size={14} weight="bold" style={{ color: 'var(--martis-accent)' }} />
                    )}
                  </button>
                )
              })
            )}
          </div>
        </div>
      )}

      {error && <p className="mt-1 text-xs text-red-500">{error}</p>}

      {canShowCreateButton && relatedResource && (
        <InlineCreateModal
          relatedResource={relatedResource}
          open={showInlineCreate}
          onClose={() => setShowInlineCreate(false)}
          onCreated={handleInlineCreated}
          modalSize={fieldModalSize}
        />
      )}
    </div>
  )
}
