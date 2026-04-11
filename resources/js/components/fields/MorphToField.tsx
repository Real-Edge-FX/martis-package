import { useState, useEffect, useRef, useCallback, useMemo } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useParams } from 'react-router-dom'
import { api } from '@/lib/api'
import type { FieldDisplayProps, FieldInputProps } from './types'
import type { PaginatedResponse } from '@/types'
import { ArrowSquareOut, CaretDown, MagnifyingGlass, X, Check, Plus } from '@phosphor-icons/react'
import { InlineCreateModal } from '@/components/InlineCreateModal'
import { useQueryClient } from '@tanstack/react-query'

interface MorphToValue {
  type: string
  id: number | string
  title?: string | null
  resourceType?: string | null
}

interface MorphTypeOption {
  value: string // resource URI key
  label: string // singular label
}

function isMorphToValue(v: unknown): v is MorphToValue {
  return v !== null && typeof v === 'object' && 'id' in (v as Record<string, unknown>) && ('type' in (v as Record<string, unknown>) || 'resourceType' in (v as Record<string, unknown>))
}

// ---------------------------------------------------------------------------
// Display
// ---------------------------------------------------------------------------

export function MorphToFieldDisplay({ value, field }: FieldDisplayProps) {
  if (value === null || value === undefined || value === '') {
    return <span className="martis-text-muted">—</span>
  }

  if (isMorphToValue(value)) {
    const label = value.title ?? String(value.id)
    const resourceType = value.resourceType
    const peekable = (field as unknown as Record<string, unknown>).peekable !== false

    if (resourceType) {
      return (
        <span className="inline-flex items-center gap-1">
          <Link
            to={`/resources/${resourceType}/${value.id}`}
            className="text-sm hover:underline"
            style={{ color: 'var(--martis-accent)' }}
          >
            {label}
          </Link>
          {peekable && (
            <Link
              to={`/resources/${resourceType}/${value.id}`}
              data-pr-tooltip="Preview"
              data-pr-position="top"
              style={{ color: 'var(--martis-text-muted)' }}
              className="inline-flex items-center opacity-60 hover:opacity-100 transition-opacity"
            >
              <ArrowSquareOut size={13} weight="regular" />
            </Link>
          )}
        </span>
      )
    }

    return <span className="martis-text">{label}</span>
  }

  return <span className="martis-text">{String(value)}</span>
}

// ---------------------------------------------------------------------------
// Input — Type selector + record selector (two-step)
// ---------------------------------------------------------------------------

interface RelatedRecord {
  id: number | string
  _title?: string
  [key: string]: unknown
}

export function MorphToFieldInput({ field, value, onChange, error, resourceKey, recordId }: FieldInputProps) {
  const { t: tMsg } = useTranslation('messages')
  const morphTypes = (field as unknown as Record<string, unknown>).morphTypes as MorphTypeOption[] | undefined
  const titleAttribute = (field as unknown as Record<string, unknown>).titleAttribute as string | undefined
  const isNullable = (field as unknown as Record<string, unknown>).nullable as boolean | undefined
  const showCreateRelationButton = (field as unknown as Record<string, unknown>).showCreateRelationButton === true
  const fieldModalSize = ((field as unknown as Record<string, unknown>).modalSize as string) || '2xl'
  const withSubtitles = (field as unknown as Record<string, unknown>).withSubtitles === true
  const subtitleAttribute = ((field as unknown as Record<string, unknown>).subtitleAttribute as string) || 'subtitle'

  const qc = useQueryClient()

  // Parse current value
  const currentValue = useMemo(() => {
    if (value === null || value === undefined || value === '') return null
    if (isMorphToValue(value)) return value
    return null
  }, [value])

  const [selectedType, setSelectedType] = useState<string | null>(currentValue?.resourceType ?? null)
  const [selectedId, setSelectedId] = useState<number | string | null>(currentValue?.id ?? null)
  const [selectedLabel, setSelectedLabel] = useState<string | null>(currentValue?.title ?? null)

  // Record selector state
  const [open, setOpen] = useState(false)
  const [search, setSearch] = useState('')
  const [options, setOptions] = useState<RelatedRecord[]>([])
  const [loading, setLoading] = useState(false)
  const [showInlineCreate, setShowInlineCreate] = useState(false)

  const containerRef = useRef<HTMLDivElement>(null)
  const searchInputRef = useRef<HTMLInputElement>(null)
  const debounceRef = useRef<ReturnType<typeof setTimeout> | null>(null)

  // Update internal state when value changes externally
  useEffect(() => {
    if (currentValue) {
      setSelectedType(currentValue.resourceType ?? null)
      setSelectedId(currentValue.id)
      setSelectedLabel(currentValue.title ?? null)
    }
  }, [currentValue])

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
  const params = useParams<{ resource?: string; id?: string }>()
  const sourceResource = resourceKey ?? params.resource
  const sourceId = recordId != null ? String(recordId) : (params.id ?? '_')

  // Fetch options for the selected type
  const fetchOptions = useCallback(async (query: string) => {
    if (!selectedType) return

    setLoading(true)
    try {
      const searchParam = query ? `&search=${encodeURIComponent(query)}` : ''
      const endpoint = sourceResource
        ? `/api/resources/${sourceResource}/${sourceId}/relatable/${field.attribute}?per_page=20&related_resource=${selectedType}${searchParam}`
        : `/api/resources/_/_/relatable/${field.attribute}?per_page=20&related_resource=${selectedType}${searchParam}`
      const res = await api.get<PaginatedResponse<RelatedRecord>>(endpoint)
      setOptions(res.data ?? [])
    } catch {
      setOptions([])
    } finally {
      setLoading(false)
    }
  }, [selectedType, sourceResource, sourceId, field.attribute])

  // Load options when dropdown opens
  useEffect(() => {
    if (open && selectedType) {
      void fetchOptions('')
    }
  }, [open, selectedType])

  // Debounced search
  function handleSearchChange(query: string) {
    setSearch(query)
    if (debounceRef.current) clearTimeout(debounceRef.current)
    debounceRef.current = setTimeout(() => {
      void fetchOptions(query)
    }, 300)
  }

  function getOptionLabel(record: RelatedRecord): string {
    if (titleAttribute && record[titleAttribute] !== undefined && record[titleAttribute] !== null) {
      return String(record[titleAttribute])
    }
    if (record._title) return record._title
    for (const attr of ['name', 'title', 'label', 'email']) {
      if (record[attr] !== undefined && record[attr] !== null) {
        return String(record[attr])
      }
    }
    return `#${record.id}`
  }

  function getOptionSubtitle(record: RelatedRecord): string | null {
    if (!withSubtitles) return null
    const sub = record[subtitleAttribute]
    if (sub !== undefined && sub !== null && sub !== '') {
      return String(sub)
    }
    return null
  }

  function handleTypeChange(resourceType: string) {
    setSelectedType(resourceType)
    setSelectedId(null)
    setSelectedLabel(null)
    setOptions([])
    setSearch('')
    // Emit partial value — type selected but no record yet
    onChange({ resourceType, id: null, type: null, title: null })
  }

  function handleSelect(record: RelatedRecord) {
    const label = getOptionLabel(record)
    setSelectedId(record.id)
    setSelectedLabel(label)
    setOpen(false)
    setSearch('')
    onChange({ resourceType: selectedType, id: record.id, title: label })
  }

  function handleClear(e: React.MouseEvent) {
    e.stopPropagation()
    e.preventDefault()
    setSelectedType(null)
    setSelectedId(null)
    setSelectedLabel(null)
    setSearch('')
    onChange(null)
  }

  function handleInlineCreated(record: { id: string | number; title: string | null }) {
    setSelectedId(record.id)
    setSelectedLabel(record.title ?? String(record.id))
    setShowInlineCreate(false)
    onChange({ resourceType: selectedType, id: record.id, title: record.title })
    void qc.invalidateQueries({ queryKey: ['relatable'] })
  }

  const canShowCreateButton = showCreateRelationButton && !!selectedType
  const selectedTypeLabel = morphTypes?.find(t => t.value === selectedType)?.label ?? selectedType

  return (
    <div ref={containerRef} className="space-y-2">
      {/* Step 1: Type selector */}
      <div>
        <select
          value={selectedType ?? ''}
          onChange={(e) => handleTypeChange(e.target.value)}
          disabled={field.readonly}
          className="martis-input block w-full rounded-md border px-3 py-2 text-sm"
          style={{
            backgroundColor: 'var(--martis-input-bg)',
            borderColor: error ? '#ef4444' : 'var(--martis-border)',
            color: 'var(--martis-text)',
            opacity: field.readonly ? 0.6 : 1,
          }}
        >
          <option value="">{tMsg('select_type', 'Select type...')}</option>
          {morphTypes?.map((t) => (
            <option key={t.value} value={t.value}>{t.label}</option>
          ))}
        </select>
      </div>

      {/* Step 2: Record selector (only when type is selected) */}
      {selectedType && (
        <div className="relative">
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
                {selectedLabel ?? (selectedId !== null ? `#${selectedId}` : (
                  <span style={{ color: 'var(--martis-text-muted)' }}>
                    {tMsg('select_record', { type: selectedTypeLabel })}
                  </span>
                ))}
              </span>

              {selectedId !== null && isNullable && !field.readonly && (
                <span
                  role="button"
                  tabIndex={-1}
                  onClick={handleClear}
                  onKeyDown={(e) => { if (e.key === 'Enter') handleClear(e as unknown as React.MouseEvent) }}
                  className="martis-belongs-to-clear"
                  data-pr-tooltip="Clear selection"
                  data-pr-position="top"
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
                data-pr-tooltip={`Create new ${selectedTypeLabel}`}
                data-pr-position="top"
              >
                <Plus size={16} weight="bold" />
              </button>
            )}
          </div>

          {/* Dropdown panel */}
          {open && (
            <div className="martis-belongs-to-dropdown">
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
                    const subtitle = getOptionSubtitle(record)
                    const isSelected = selectedId !== null && String(record.id) === String(selectedId)
                    return (
                      <button
                        key={record.id}
                        type="button"
                        onClick={() => handleSelect(record)}
                        className={`martis-belongs-to-option ${isSelected ? 'martis-belongs-to-option--selected' : ''}`}
                      >
                        <span className="flex-1 min-w-0">
                          <span className="martis-belongs-to-option-label block">{label}</span>
                          {subtitle && (
                            <span
                              className="block text-xs truncate"
                              style={{ color: 'var(--martis-text-muted)' }}
                            >
                              {subtitle}
                            </span>
                          )}
                        </span>
                        {isSelected && (
                          <Check size={14} weight="bold" style={{ color: 'var(--martis-accent)', flexShrink: 0 }} />
                        )}
                      </button>
                    )
                  })
                )}
              </div>
            </div>
          )}
        </div>
      )}

      {error && <p className="mt-1 text-xs text-red-500">{error}</p>}

      {canShowCreateButton && selectedType && (
        <InlineCreateModal
          relatedResource={selectedType}
          open={showInlineCreate}
          onClose={() => setShowInlineCreate(false)}
          onCreated={handleInlineCreated}
          modalSize={fieldModalSize}
        />
      )}
    </div>
  )
}
