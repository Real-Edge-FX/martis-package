import { useRef, useState, useEffect, useCallback } from 'react'
import { useTranslation } from 'react-i18next'
import { CaretDownIcon, XIcon, CheckIcon, MagnifyingGlassIcon } from '@phosphor-icons/react'
import type { FieldDisplayProps, FieldInputProps } from './types'
import { ClearButton } from '@/components/ClearButton'

interface SelectOpt {
  label: string
  value: string | number
  group?: string
}

function getOptions(field: Record<string, unknown>): SelectOpt[] {
  return (field.options as SelectOpt[] | undefined) ?? []
}

function toLabelOrValue(
  val: unknown,
  options: SelectOpt[],
  displayLabels: boolean,
): string {
  const str = String(val ?? '')
  if (displayLabels) {
    const opt = options.find((o) => String(o.value) === str)
    return opt?.label ?? str
  }
  return str
}

function toArray(value: unknown): string[] {
  if (!value) return []
  if (Array.isArray(value)) return value.map(String)
  if (typeof value === 'string') {
    try {
      const parsed = JSON.parse(value) as unknown
      if (Array.isArray(parsed)) return parsed.map(String)
    } catch {
      // ignore
    }
  }
  return []
}

// ---------------------------------------------------------------------------
// Display
// ---------------------------------------------------------------------------

export function MultiSelectFieldDisplay({ field, value }: FieldDisplayProps) {
  const values = toArray(value)

  if (values.length === 0) {
    return <span className="martis-text-muted">—</span>
  }

  const options = getOptions(field as Record<string, unknown>)
  const displayLabels = (field as Record<string, unknown>).displayLabels as boolean | undefined

  return (
    <div className="flex flex-wrap gap-1">
      {values.map((v) => (
        <span
          key={v}
          className="martis-badge"
          style={{
            backgroundColor: 'var(--martis-surface-alt)',
            color: 'var(--martis-text)',
            borderColor: 'var(--martis-border)',
          }}
        >
          {toLabelOrValue(v, options, !!displayLabels)}
        </span>
      ))}
    </div>
  )
}

// ---------------------------------------------------------------------------
// Input — custom multi-select with chips
// ---------------------------------------------------------------------------

export function MultiSelectFieldInput({ field, value, onChange, error }: FieldInputProps) {
  const { t: tMsg } = useTranslation('messages')
  const options = getOptions(field as Record<string, unknown>)
  const selected = toArray(value)

  const [open, setOpen] = useState(false)
  const [search, setSearch] = useState('')
  const [debouncedSearch, setDebouncedSearch] = useState('')
  const containerRef = useRef<HTMLDivElement>(null)
  const debounceRef = useRef<ReturnType<typeof setTimeout> | null>(null)

  useEffect(() => {
    function handleOutside(e: MouseEvent) {
      if (containerRef.current && !containerRef.current.contains(e.target as Node)) {
        setOpen(false)
        setSearch('')
        setDebouncedSearch('')
      }
    }
    document.addEventListener('mousedown', handleOutside)
    return () => document.removeEventListener('mousedown', handleOutside)
  }, [])

  // Cleanup debounce on unmount
  useEffect(() => {
    return () => {
      if (debounceRef.current) clearTimeout(debounceRef.current)
    }
  }, [])

  const handleSearchChange = useCallback((query: string) => {
    setSearch(query)
    if (debounceRef.current) clearTimeout(debounceRef.current)
    debounceRef.current = setTimeout(() => {
      setDebouncedSearch(query)
    }, 300)
  }, [])

  function toggle(val: string) {
    if (field.readonly) return
    const next = selected.includes(val)
      ? selected.filter((v) => v !== val)
      : [...selected, val]
    onChange(next)
  }

  function removeChip(val: string, e: React.MouseEvent) {
    e.stopPropagation()
    if (field.readonly) return
    onChange(selected.filter((v) => v !== val))
  }

  const filteredOptions = options.filter((o) =>
    o.label.toLowerCase().includes(debouncedSearch.toLowerCase()) ||
    String(o.value).toLowerCase().includes(debouncedSearch.toLowerCase()),
  )

  // Group options
  const groupedOptions: { group: string | null; items: SelectOpt[] }[] = []
  const seen = new Set<string>()
  filteredOptions.forEach((o) => {
    const g = o.group ?? null
    const key = g ?? '__none__'
    if (!seen.has(key)) {
      seen.add(key)
      groupedOptions.push({ group: g, items: [] })
    }
    groupedOptions.find((x) => (x.group ?? '__none__') === key)!.items.push(o)
  })

  const getLabel = (val: string) => {
    const opt = options.find((o) => String(o.value) === val)
    return opt?.label ?? val
  }

  const showClear = !!field.nullable && selected.length > 0 && !field.readonly

  return (
    <div ref={containerRef} className="flex flex-col gap-1 relative">
      {/* Trigger with chips */}
      <div
        onClick={() => !field.readonly && setOpen(!open)}
        className="martis-input flex flex-wrap items-center gap-1 cursor-pointer"
        style={{
          minHeight: '2.375rem',
          padding: showClear ? '0.375rem 3.25rem 0.375rem 0.5rem' : '0.375rem 2rem 0.375rem 0.5rem',
          position: 'relative',
          opacity: field.readonly ? 0.7 : 1,
          cursor: field.readonly ? 'not-allowed' : 'pointer',
          ...(error ? { borderColor: 'var(--martis-danger)' } : {}),
          ...(open && !error ? { borderColor: 'var(--martis-accent)' } : {}),
        }}
      >
        {selected.length === 0 ? (
          <span style={{ color: 'var(--martis-text-muted)', fontSize: '0.875rem' }}>
            {field.placeholder ?? tMsg('select')}
          </span>
        ) : (
          selected.map((v) => (
            <span
              key={v}
              className="inline-flex items-center gap-0.5 rounded px-1.5 py-0.5 text-xs font-medium"
              style={{
                backgroundColor: 'var(--martis-surface-alt)',
                color: 'var(--martis-text)',
                border: '1px solid var(--martis-border)',
              }}
            >
              {getLabel(v)}
              {!field.readonly && (
                <button
                  type="button"
                  onClick={(e) => removeChip(v, e)}
                  className="ml-0.5 opacity-70 hover:opacity-100 transition-opacity"
                  style={{ color: 'var(--martis-danger)', lineHeight: 1, background: 'none', border: 'none', cursor: 'pointer', padding: 0 }}
                >
                  <XIcon size={10} weight="bold" />
                </button>
              )}
            </span>
          ))
        )}
        <ClearButton
          visible={showClear}
          onClick={() => onChange([])}
          style={{ position: 'absolute', right: '1.75rem', top: '50%', transform: 'translateY(-50%)' }}
        />
        <CaretDownIcon
          size={12}
          weight="bold"
          style={{
            position: 'absolute',
            right: '0.625rem',
            top: '50%',
            transform: 'translateY(-50%)',
            color: 'var(--martis-text-muted)',
          }}
        />
      </div>

      {/* Dropdown */}
      {open && (
        <div
          className="absolute z-50 w-full rounded-md shadow-lg"
          style={{
            top: 'calc(100% + 4px)',
            backgroundColor: 'var(--martis-surface)',
            border: '1px solid var(--martis-border)',
            maxHeight: '16rem',
            overflow: 'hidden',
            display: 'flex',
            flexDirection: 'column',
          }}
        >
          {/* Search — aligned to BelongsTo style (icon + borderless input) */}
          <div className="martis-belongs-to-search">
            <MagnifyingGlassIcon size={14} style={{ color: 'var(--martis-text-muted)', flexShrink: 0 }} />
            <input
              autoFocus
              type="text"
              value={search}
              onChange={(e) => handleSearchChange(e.target.value)}
              placeholder={tMsg('search')}
              className="martis-belongs-to-search-input"
            />
          </div>

          {/* Options — reuse `.martis-belongs-to-*` classes so the panel
              matches every other relation/select dropdown in the system. */}
          <div className="martis-belongs-to-options" style={{ flex: 1 }}>
            {filteredOptions.length === 0 ? (
              <div className="martis-belongs-to-empty">
                {debouncedSearch ? tMsg('no_results_found') : tMsg('no_options')}
              </div>
            ) : (
              groupedOptions.map(({ group, items }) => (
                <div key={group ?? '__none__'}>
                  {group && (
                    <div
                      style={{
                        padding: '0.25rem 0.75rem',
                        fontSize: '0.625rem',
                        fontWeight: 700,
                        letterSpacing: '0.05em',
                        textTransform: 'uppercase' as const,
                        color: 'var(--martis-text-muted)',
                        borderBottom: '1px solid var(--martis-border)',
                      }}
                    >
                      {group}
                    </div>
                  )}
                  {items.map((opt) => {
                    const val = String(opt.value)
                    const isSelected = selected.includes(val)
                    return (
                      <button
                        key={val}
                        type="button"
                        onClick={() => toggle(val)}
                        className={`martis-belongs-to-option ${isSelected ? 'martis-belongs-to-option--selected' : ''}`}
                      >
                        <span className="martis-belongs-to-option-label flex-1 min-w-0 block">{opt.label}</span>
                        {isSelected && (
                          <CheckIcon size={12} weight="bold" style={{ color: 'var(--martis-accent)', flexShrink: 0 }} />
                        )}
                      </button>
                    )
                  })}
                </div>
              ))
            )}
          </div>
        </div>
      )}

      {error && <small className="text-red-500">{error}</small>}
    </div>
  )
}
