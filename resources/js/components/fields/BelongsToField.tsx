import { useState, useEffect, useRef, useCallback, useMemo } from 'react'
import { Link } from 'react-router-dom'
import { api } from '@/lib/api'
import type { FieldDisplayProps, FieldInputProps } from './types'
import type { PaginatedResponse } from '@/types'
import { CaretDown, MagnifyingGlass, X } from '@phosphor-icons/react'

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

    if (relatedResource) {
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
// Input — Searchable dropdown
// ---------------------------------------------------------------------------

interface RelatedRecord {
  id: number | string
  _title?: string
  [key: string]: unknown
}

export function BelongsToFieldInput({ field, value, onChange, error }: FieldInputProps) {
  const relatedResource = (field as unknown as Record<string, unknown>).relatedResource as string | undefined
  const titleAttribute = (field as unknown as Record<string, unknown>).titleAttribute as string | undefined

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

  // Fetch options from related resource API
  const fetchOptions = useCallback(async (query: string) => {
    if (!relatedResource) return

    setLoading(true)
    try {
      const searchParam = query ? `&search=${encodeURIComponent(query)}` : ''
      const res = await api.get<PaginatedResponse<RelatedRecord>>(
        `/api/resources/${relatedResource}?per_page=20${searchParam}`
      )
      setOptions(res.data ?? [])
    } catch {
      setOptions([])
    } finally {
      setLoading(false)
    }
  }, [relatedResource])

  // Load options when dropdown opens
  useEffect(() => {
    if (open) {
      void fetchOptions(search)
    }
  }, [open, fetchOptions, search])

  // Debounced search
  function handleSearchChange(query: string) {
    setSearch(query)
    if (debounceRef.current) clearTimeout(debounceRef.current)
    debounceRef.current = setTimeout(() => {
      void fetchOptions(query)
    }, 300)
  }

  function getOptionLabel(record: RelatedRecord): string {
    if (record._title) return record._title
    if (titleAttribute && record[titleAttribute] !== undefined) {
      return String(record[titleAttribute])
    }
    // Try common label attributes
    for (const attr of ['name', 'title', 'label', 'email']) {
      if (record[attr] !== undefined && record[attr] !== null) {
        return String(record[attr])
      }
    }
    return String(record.id)
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
      {/* Trigger button */}
      <button
        type="button"
        onClick={() => !field.readonly && setOpen(!open)}
        disabled={field.readonly}
        className={[
          'flex w-full items-center gap-2 rounded-md border px-3 py-2 text-left text-sm transition-colors',
          field.readonly ? 'cursor-not-allowed opacity-60' : 'cursor-pointer',
        ].join(' ')}
        style={{
          backgroundColor: 'var(--martis-input-bg)',
          borderColor: error ? '#ef4444' : open ? 'var(--martis-accent)' : 'var(--martis-border)',
          color: 'var(--martis-text)',
        }}
      >
        <span className="flex-1 truncate">
          {selectedLabel ?? (currentId !== null ? `#${currentId}` : (
            <span style={{ color: 'var(--martis-text-muted)' }}>
              {field.nullable ? 'Select...' : `Select ${field.label}...`}
            </span>
          ))}
        </span>

        {currentId !== null && field.nullable && !field.readonly && (
          <span
            role="button"
            tabIndex={-1}
            onClick={handleClear}
            onKeyDown={(e) => { if (e.key === 'Enter') handleClear(e as unknown as React.MouseEvent) }}
            className="flex-shrink-0 rounded-full p-0.5 hover:bg-red-500/10"
          >
            <X size={14} className="text-red-400" />
          </span>
        )}

        <CaretDown size={14} className="flex-shrink-0 martis-text-muted" />
      </button>

      {/* Dropdown panel */}
      {open && (
        <div
          className="absolute left-0 right-0 z-50 mt-1 overflow-hidden rounded-md border shadow-lg"
          style={{
            backgroundColor: 'var(--martis-card, var(--martis-surface))',
            borderColor: 'var(--martis-border)',
          }}
        >
          {/* Search input */}
          <div className="border-b px-3 py-2" style={{ borderColor: 'var(--martis-border)' }}>
            <div className="flex items-center gap-2">
              <MagnifyingGlass size={14} className="martis-text-muted flex-shrink-0" />
              <input
                ref={searchInputRef}
                type="text"
                value={search}
                onChange={(e) => handleSearchChange(e.target.value)}
                placeholder="Search..."
                className="w-full bg-transparent text-sm outline-none"
                style={{ color: 'var(--martis-text)' }}
              />
            </div>
          </div>

          {/* Options list */}
          <div className="max-h-60 overflow-y-auto">
            {loading ? (
              <div className="px-3 py-4 text-center text-sm martis-text-muted">Loading...</div>
            ) : options.length === 0 ? (
              <div className="px-3 py-4 text-center text-sm martis-text-muted">
                {search ? 'No results found' : 'No records available'}
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
                    className="flex w-full items-center gap-2 px-3 py-2 text-left text-sm transition-colors"
                    style={{
                      backgroundColor: isSelected ? 'var(--martis-accent-light, rgba(99,102,241,0.1))' : 'transparent',
                      color: 'var(--martis-text)',
                    }}
                    onMouseEnter={(e) => {
                      if (!isSelected) e.currentTarget.style.backgroundColor = 'var(--martis-hover)'
                    }}
                    onMouseLeave={(e) => {
                      if (!isSelected) e.currentTarget.style.backgroundColor = 'transparent'
                    }}
                  >
                    <span className="flex-1 truncate">{label}</span>
                    {isSelected && (
                      <span className="text-xs font-medium" style={{ color: 'var(--martis-accent)' }}>✓</span>
                    )}
                  </button>
                )
              })
            )}
          </div>
        </div>
      )}

      {error && <p className="mt-1 text-xs text-red-500">{error}</p>}
    </div>
  )
}
