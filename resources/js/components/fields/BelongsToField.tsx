import { useState, useEffect, useRef, useCallback, useMemo, useId } from 'react'
import { createPortal } from 'react-dom'
import { useTranslation } from 'react-i18next'
import { Link, useParams } from 'react-router-dom'
import { api } from '@/lib/api'
import type { FieldDisplayProps, FieldInputProps } from './types'
import type { PaginatedResponse } from '@/types'
import { ArrowSquareOutIcon, CaretDownIcon, MagnifyingGlassIcon, XIcon, CheckIcon, PlusIcon } from '@phosphor-icons/react'
import { InlineCreateModal } from '@/components/InlineCreateModal'
import { ResourceIcon } from '@/components/ResourceIcon'
import { useQueryClient } from '@tanstack/react-query'
// Tooltip handled by global <Tooltip> in Layout.tsx

interface BelongsToValue {
  id: number | string
  title?: string | null
  subtitle?: string | null
}

function isBelongsToValue(v: unknown): v is BelongsToValue {
  return v !== null && typeof v === 'object' && 'id' in (v as Record<string, unknown>)
}

// ---------------------------------------------------------------------------
// PeekCard — hover preview card fetching content from the resource's
// fieldsForPreview() via the /peek endpoint.
// The card is triggered exclusively by the preview icon, never by hover on the link.
// ---------------------------------------------------------------------------

interface PeekAttribute {
  label: string
  value: unknown
}

interface PeekData {
  title: string
  attributes: PeekAttribute[]
}

interface PeekResponse {
  data: PeekData
}

interface PeekCardProps {
  resourceKey: string
  recordId: number | string
  /** Bounding rect of the trigger element — the card flips above it when
   *  there isn't enough room below. */
  triggerRect: { top: number; bottom: number; left: number }
  /** Notifica o pai quando o card foi virado acima do trigger. Permite
   *  that the trigger's tooltip is repositioned to "bottom" and does not
   *  fique sobreposto pelo próprio card. */
  onFlipChange?: (flipped: boolean) => void
}

function renderPeekValue(value: unknown): React.ReactNode {
  if (value === null || value === undefined) return '—'
  if (typeof value === 'boolean') return value ? '✓' : '✗'
  if (typeof value === 'object') {
    const obj = value as Record<string, unknown>
    // Icon field — resolveForDisplay returns `{icon, color}`. Render it
    // inline with the same ResourceIcon component used elsewhere so the
    // peek card shows the actual glyph, not a dash.
    if (typeof obj.icon === 'string') {
      const color = typeof obj.color === 'string' ? obj.color : undefined
      return (
        <span className="inline-flex items-center gap-1" style={{ color: color ?? 'var(--martis-text)' }}>
          <ResourceIcon iconName={obj.icon} size={14} />
        </span>
      )
    }
    if (obj.title != null) return String(obj.title)
    if (obj.id != null) return `#${obj.id}`
    return '—'
  }
  const str = String(value)
  return str === '' ? '—' : str
}

function PeekCard({ resourceKey, recordId, triggerRect, onFlipChange }: PeekCardProps) {
  const { t } = useTranslation('messages')
  const [data, setData] = useState<PeekData | null>(null)
  const [loading, setLoading] = useState(true)
  const cardRef = useRef<HTMLDivElement | null>(null)
  const [position, setPosition] = useState<{ top: number; left: number }>({
    top: triggerRect.bottom + 6,
    left: triggerRect.left,
  })
  // Keep the card invisible on the first paint. The position is only
  // correct AFTER we measure it via useEffect below — rendering visibly
  // before that produces a tiny "appears wrong → jumps" flicker.
  const [positioned, setPositioned] = useState(false)

  useEffect(() => {
    let cancelled = false
    api.get<PeekResponse>(`/api/resources/${resourceKey}/${recordId}/peek`)
      .then((res) => {
        if (!cancelled) {
          setData(res.data)
          setLoading(false)
        }
      })
      .catch(() => {
        if (!cancelled) setLoading(false)
      })
    return () => { cancelled = true }
  }, [resourceKey, recordId])

  // Once the card is laid out, measure it and flip above the trigger if
  // there isn't enough room below. Only decides after loading finishes to
  // avoid oscillation: during loading the card is ~50px and would fit
  // below; when data arrives it grows and may no longer fit. Waiting for
  // the final measurement guarantees a single stable decision.
  useEffect(() => {
    if (!cardRef.current) return
    if (loading) return
    const rect = cardRef.current.getBoundingClientRect()
    const gap = 6
    const viewportH = window.innerHeight
    const spaceBelow = viewportH - triggerRect.bottom - gap
    const spaceAbove = triggerRect.top - gap
    const needsFlip = rect.height > spaceBelow && spaceAbove > spaceBelow

    // Also clamp horizontally so we don't flow past the right edge.
    const viewportW = window.innerWidth
    const clampedLeft = Math.max(8, Math.min(triggerRect.left, viewportW - rect.width - 8))

    setPosition({
      top: needsFlip ? triggerRect.top - rect.height - gap : triggerRect.bottom + gap,
      left: clampedLeft,
    })
    setPositioned(true)
    onFlipChange?.(needsFlip)
  }, [loading, data, triggerRect.top, triggerRect.bottom, triggerRect.left, onFlipChange])

  const hasAttributes = data && data.attributes.length > 0

  return createPortal(
    <div
      ref={cardRef}
      data-testid="peek-card"
      className="fixed rounded-lg border p-2.5 text-sm pointer-events-none"
      style={{
        backgroundColor: 'var(--martis-surface)',
        borderColor: 'var(--martis-border)',
        color: 'var(--martis-text)',
        zIndex: 9999,
        boxShadow: 'var(--martis-peek-shadow)',
        top: `${position.top}px`,
        left: `${position.left}px`,
        minWidth: '10rem',
        maxWidth: '20rem',
        width: 'max-content',
        visibility: positioned ? 'visible' : 'hidden',
      }}
    >
      {loading ? (
        <p className="text-xs" style={{ color: 'var(--martis-text-muted)' }}>
          {t('loading', { defaultValue: 'Loading…' })}
        </p>
      ) : data ? (
        <>
          <p className="font-medium leading-snug truncate">{data.title}</p>
          {hasAttributes && (
            <table className="w-full mt-1.5 border-collapse">
              <tbody>
                {data.attributes.map(({ label, value }) => (
                  <tr key={label}>
                    <td
                      className="text-xs pr-2 py-0.5 align-top whitespace-nowrap"
                      style={{ color: 'var(--martis-text-muted)' }}
                    >
                      {label}
                    </td>
                    <td
                      className="text-xs py-0.5 align-top overflow-hidden"
                      style={{
                        color: 'var(--martis-text)',
                        maxWidth: '12rem',
                        textOverflow: 'ellipsis',
                        whiteSpace: 'nowrap',
                      }}
                    >
                      {renderPeekValue(value)}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </>
      ) : null}
    </div>,
    document.body
  )
}

// ---------------------------------------------------------------------------
// Display — Index / Detail
// ---------------------------------------------------------------------------

export function BelongsToFieldDisplay({ value, field }: FieldDisplayProps) {
  const { t: tMsg } = useTranslation('messages')
  const instanceId = useId()
  const peekArrowClass = `peek-arrow-${instanceId.replace(/:/g, '')}`
  const [showPeek, setShowPeek] = useState(false)
  const [peekTriggerRect, setPeekTriggerRect] = useState<{ top: number; bottom: number; left: number } | null>(null)
  // true when the peek card didn't fit below and flipped above; in that
  // case the "Preview" tooltip must flip down so it isn't overlapped by
  // the card.
  const [peekFlipped, setPeekFlipped] = useState(false)
  const peekTimer = useRef<ReturnType<typeof setTimeout> | null>(null)
  const containerSpanRef = useRef<HTMLSpanElement>(null)
  const peekIconRef = useRef<HTMLAnchorElement>(null)

  function handleMouseEnter() {
    // Predict the flip IMMEDIATELY (synchronously) so data-pr-position is
    // already correct when MartisTooltip fires after 300ms. If we wait
    // for the setTimeout below, there's a race: the tooltip reads
    // data-pr-position="top" before React applies the re-render, picks up
    // the old value, and only corrects later via the observer — which
    // the user sees as "opens above and jumps below".
    const target = peekIconRef.current ?? containerSpanRef.current
    if (target) {
      const rect = target.getBoundingClientRect()
      const gap = 6
      const spaceBelow = window.innerHeight - rect.bottom - gap
      const spaceAbove = rect.top - gap
      // Conservative prediction: open downward by default; only consider
      // flipping when space below is small (< 200px — enough for a
      // typical 3-6 field card) AND there is more space above. This
      // avoids flipping up on rows in the middle of the viewport where
      // the card fits perfectly below.
      setPeekFlipped(spaceBelow < 200 && spaceAbove > spaceBelow)
    }
    peekTimer.current = setTimeout(() => {
      const t2 = peekIconRef.current ?? containerSpanRef.current
      if (t2) {
        const rect = t2.getBoundingClientRect()
        setPeekTriggerRect({ top: rect.top, bottom: rect.bottom, left: rect.left })
      }
      setShowPeek(true)
    }, 300)
  }

  function handleMouseLeave() {
    if (peekTimer.current) clearTimeout(peekTimer.current)
    setShowPeek(false)
    setPeekTriggerRect(null)
    setPeekFlipped(false)
  }

  if (value === null || value === undefined || value === '') {
    return <span className="martis-text-muted">{tMsg('belongs_to_empty', { defaultValue: '—' })}</span>
  }

  // Multiple mode: value is an array of {id, title} objects
  const isMultiple = (field as unknown as Record<string, unknown>).multiple === true
  if (isMultiple || Array.isArray(value)) {
    const items = Array.isArray(value) ? (value as unknown[]).filter(isBelongsToValue) : []
    if (items.length === 0) {
      return <span className="martis-text-muted">{tMsg('belongs_to_empty', { defaultValue: '—' })}</span>
    }
    const relatedResourceMulti = (field as unknown as Record<string, unknown>).relatedResource as string | undefined
    const displayAsLinkMulti = (field as unknown as Record<string, unknown>).displayAsLink !== false
    return (
      <div className="flex flex-wrap gap-1">
        {items.map((item) => {
          const label = item.title ?? String(item.id)
          if (relatedResourceMulti && displayAsLinkMulti) {
            return (
              <Link
                key={item.id}
                to={`/resources/${relatedResourceMulti}/${item.id}`}
                className="martis-badge hover:underline"
                style={{ backgroundColor: 'var(--martis-surface)', color: 'var(--martis-accent)', borderColor: 'var(--martis-border)' }}
              >
                {label}
              </Link>
            )
          }
          return (
            <span
              key={item.id}
              className="martis-badge"
              style={{ backgroundColor: 'var(--martis-surface)', color: 'var(--martis-text)', borderColor: 'var(--martis-border)' }}
            >
              {label}
            </span>
          )
        })}
      </div>
    )
  }

  if (isBelongsToValue(value)) {
    const label = value.title ?? String(value.id)
    const relatedResource = (field as unknown as Record<string, unknown>).relatedResource as string | undefined
    const displayAsLink = (field as unknown as Record<string, unknown>).displayAsLink !== false
    const peekable = (field as unknown as Record<string, unknown>).peekable !== false

    if (relatedResource && displayAsLink) {
      return (
        <span
          ref={containerSpanRef}
          className="inline-flex items-center gap-1"
        >
          <Link
            to={`/resources/${relatedResource}/${value.id}`}
            className="text-sm hover:underline"
            style={{ color: 'var(--martis-accent)' }}
          >
            {label}
          </Link>
          {peekable && (
            <a
              ref={peekIconRef}
              href="#"
              onClick={(e) => e.preventDefault()}
              aria-label={tMsg('preview', { defaultValue: 'Preview' })}
              style={{ color: 'var(--martis-text-muted)' }}
              className={`inline-flex items-center opacity-60 hover:opacity-100 transition-opacity ${peekArrowClass}`}
              onMouseEnter={handleMouseEnter}
              onMouseLeave={handleMouseLeave}
            >
              <ArrowSquareOutIcon size={13} weight="regular" />
            </a>
          )}
          {/* Tooltip handled by global Layout <Tooltip> */}
          {peekable && showPeek && peekTriggerRect && relatedResource && (
            <PeekCard
              resourceKey={relatedResource}
              recordId={value.id}
              triggerRect={peekTriggerRect}
              onFlipChange={setPeekFlipped}
            />
          )}
        </span>
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
  const isMultiple = (field as unknown as Record<string, unknown>).multiple === true
  const showCreateRelationButton = (field as unknown as Record<string, unknown>).showCreateRelationButton === true
  const fieldModalSize = ((field as unknown as Record<string, unknown>).modalSize as string) || '2xl'
  const hideCreateButton = (field as unknown as Record<string, unknown>).hideCreateButton === true
  const canShowCreateButton = showCreateRelationButton && !!relatedResource && !hideCreateButton
  const withSubtitles = (field as unknown as Record<string, unknown>).withSubtitles === true
  const subtitleAttribute = ((field as unknown as Record<string, unknown>).subtitleAttribute as string) || 'subtitle'
  const showResourceIcon = (field as unknown as Record<string, unknown>).showResourceIcon === true
  const resourceIconOverride = (field as unknown as Record<string, unknown>).resourceIconOverride as string | undefined
  const resourceSubtitleField = (field as unknown as Record<string, unknown>).resourceSubtitle as string | boolean | undefined
  const createButtonIconField = (field as unknown as Record<string, unknown>).createButtonIcon as string | undefined
  const createButtonColorField = (field as unknown as Record<string, unknown>).createButtonColor as string | undefined
  const fieldPlaceholder = (field as unknown as Record<string, unknown>).placeholder as string | undefined
  const resourceIconColor = (field as unknown as Record<string, unknown>).iconColor as string | undefined

  // Extract current ID from value (handles both plain ID and {id, title} objects)
  const currentId = useMemo(() => {
    if (isMultiple) return null
    if (value === null || value === undefined || value === '') return null
    if (isBelongsToValue(value)) return value.id
    return value
  }, [value, isMultiple])

  const currentTitle = useMemo(() => {
    if (isMultiple) return null
    if (isBelongsToValue(value)) return value.title ?? null
    return null
  }, [value, isMultiple])

  // Multiple mode: selected items array
  const [selectedItems, setSelectedItems] = useState<Array<{id: number | string; title: string | null}>>(() => {
    if (!isMultiple || !Array.isArray(value)) return []
    return (value as unknown[])
      .filter(v => isBelongsToValue(v as BelongsToValue))
      .map(v => { const bv = v as BelongsToValue; return { id: bv.id, title: bv.title ?? null } })
  })

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

  // Sync selectedItems when value changes in multiple mode (e.g. edit form loads from server)
  // Only sync when value contains BelongsToValue objects — skip when it contains plain IDs
  // (plain IDs come from onChange calls during user interaction and must not reset selectedItems)
  useEffect(() => {
    if (isMultiple && Array.isArray(value)) {
      const hasBelongsToValues = (value as unknown[]).some(v => isBelongsToValue(v as BelongsToValue))
      if (hasBelongsToValues) {
        setSelectedItems(
          (value as unknown[])
            .filter(v => isBelongsToValue(v as BelongsToValue))
            .map(v => { const bv = v as BelongsToValue; return { id: bv.id, title: bv.title ?? null } })
        )
      }
    }
  }, [isMultiple, JSON.stringify(value)])

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
  const params = useParams<{ resource?: string; id?: string }>()
  const sourceResource = resourceKey ?? params.resource
  const sourceId = recordId != null ? String(recordId) : (params.id ?? '_')

  // Fetch options from relatable endpoint (applies relatableQuery hooks)
  const fetchOptions = useCallback(async (query: string) => {
    if (!relatedResource) return

    setLoading(true)
    try {
      const searchParam = query ? `&search=${encodeURIComponent(query)}` : ''
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

  // Load initial options when dropdown opens
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
    // Extract text even when the attribute was serialized as a Stack
    // (`{ __martisStack: true, entries: [...] }`) — otherwise the dropdown
    // would render "[object Object]" for every option.
    const extract = (v: unknown): string | null => {
      if (v === undefined || v === null) return null
      if (typeof v === 'string') return v
      if (typeof v === 'number' || typeof v === 'boolean') return String(v)
      if (typeof v === 'object') {
        const obj = v as Record<string, unknown>
        if (obj.__martisStack && Array.isArray(obj.entries)) {
          const heading = obj.entries.find((e: unknown) => (e as { variant?: string }).variant === 'heading') as { text?: unknown } | undefined
          const first = obj.entries[0] as { text?: unknown } | undefined
          const text = heading?.text ?? first?.text
          return text != null ? String(text) : null
        }
        // Um objecto genérico sem estrutura conhecida — prefere o
        // fallback para _title em vez de devolver "[object Object]".
        return null
      }
      return null
    }
    if (titleAttribute) {
      const fromAttr = extract(record[titleAttribute])
      if (fromAttr !== null) return fromAttr
    }
    if (record._title) return record._title
    for (const attr of ['name', 'title', 'label', 'email']) {
      const fromAttr = extract(record[attr])
      if (fromAttr !== null) return fromAttr
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

  // Multiple mode: toggle selection
  function handleMultipleSelect(record: RelatedRecord) {
    const label = getOptionLabel(record)
    setSelectedItems(prev => {
      const exists = prev.some(item => String(item.id) === String(record.id))
      const next = exists
        ? prev.filter(item => String(item.id) !== String(record.id))
        : [...prev, { id: record.id, title: label }]
      onChange(next.map(item => item.id))
      return next
    })
  }

  // Multiple mode: clear all
  function handleMultipleClear(e: React.MouseEvent) {
    e.stopPropagation()
    e.preventDefault()
    setSelectedItems([])
    onChange([])
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

  // Multiple mode: return multi-select UI
  if (isMultiple) {
    const multiTriggerLabel = selectedItems.length === 0
      ? null
      : selectedItems.length <= 2
        ? selectedItems.map(item => item.title ?? `#${item.id}`).join(', ')
        : tMsg('belongs_to_multiple_selected', { n: selectedItems.length, defaultValue: `${selectedItems.length} selected` })

    return (
      <div ref={containerRef} className="relative">
        <button
          type="button"
          onClick={() => !field.readonly && setOpen(!open)}
          disabled={field.readonly}
          className="martis-belongs-to-trigger"
          style={{
            width: '100%',
            borderColor: error ? 'var(--martis-danger)' : open ? 'var(--martis-accent)' : 'var(--martis-border)',
            opacity: field.readonly ? 0.6 : 1,
            cursor: field.readonly ? 'not-allowed' : 'pointer',
          }}
        >
          <span className="martis-belongs-to-trigger-label">
            {multiTriggerLabel ?? (
              <span style={{ color: 'var(--martis-text-muted)' }}>
                {fieldPlaceholder ?? tMsg('select_field', { field: field.label })}
              </span>
            )}
          </span>
          {selectedItems.length > 0 && !field.readonly && (
            <span
              role="button"
              tabIndex={-1}
              onClick={handleMultipleClear}
              onKeyDown={(e) => { if (e.key === 'Enter') handleMultipleClear(e as unknown as React.MouseEvent) }}
              className="martis-belongs-to-clear martis-clear-btn-multi"
              data-pr-tooltip={tMsg('belongs_to_clear', { defaultValue: 'Clear selection' })}
              data-pr-position="top"
            >
              <XIcon size={14} weight="bold" />
            </span>
          )}
          <CaretDownIcon
            size={14}
            weight="bold"
            style={{ color: 'var(--martis-text-muted)', flexShrink: 0 }}
          />
        </button>
        {/* Tooltip handled by global Layout <Tooltip> */}

        {open && (
          <div className="martis-belongs-to-dropdown">
            <div className="martis-belongs-to-search">
              <MagnifyingGlassIcon size={14} style={{ color: 'var(--martis-text-muted)', flexShrink: 0 }} />
              <input
                ref={searchInputRef}
                type="text"
                value={search}
                onChange={(e) => handleSearchChange(e.target.value)}
                placeholder={tMsg('belongs_to_search_placeholder', { defaultValue: 'Search…' })}
                className="martis-belongs-to-search-input"
              />
            </div>
            <div className="martis-belongs-to-options">
              {loading && options.length === 0 ? (
                <div className="martis-belongs-to-empty">{tMsg('loading')}</div>
              ) : !loading && options.length === 0 ? (
                <div className="martis-belongs-to-empty">
                  {search
                    ? tMsg('belongs_to_no_results', { defaultValue: 'No results' })
                    : tMsg('no_records_available')}
                </div>
              ) : (
                options.map((record) => {
                  const label = getOptionLabel(record)
                  const isSelected = selectedItems.some(item => String(item.id) === String(record.id))
                  return (
                    <button
                      key={record.id}
                      type="button"
                      onClick={() => handleMultipleSelect(record)}
                      className={`martis-belongs-to-option ${isSelected ? 'martis-belongs-to-option--selected' : ''}`}
                    >
                      <span className="martis-belongs-to-option-label flex-1 min-w-0 block">{label}</span>
                      {isSelected && (
                        <CheckIcon size={14} weight="bold" style={{ color: 'var(--martis-accent)', flexShrink: 0 }} />
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
            borderColor: error ? 'var(--martis-danger)' : 'var(--martis-border)',
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
          borderColor: error ? 'var(--martis-danger)' : open ? 'var(--martis-accent)' : 'var(--martis-border)',
          opacity: field.readonly ? 0.6 : 1,
          cursor: field.readonly ? 'not-allowed' : 'pointer',
        }}
      >
        <span className="martis-belongs-to-trigger-label">
          {selectedLabel ?? (currentId !== null ? `#${currentId}` : (
            <span style={{ color: 'var(--martis-text-muted)' }}>
              {fieldPlaceholder ?? tMsg('select_field', { field: field.label })}
            </span>
          ))}
        </span>

        {currentId !== null && isNullable && !field.readonly && (
          <span
            role="button"
            tabIndex={-1}
            onClick={handleClear}
            onKeyDown={(e) => { if (e.key === 'Enter') handleClear(e as unknown as React.MouseEvent) }}
            className="martis-belongs-to-clear martis-clear-btn"
            data-pr-tooltip={tMsg('belongs_to_clear', { defaultValue: 'Clear selection' })}
            data-pr-position="top"
          >
            <XIcon size={14} weight="bold" />
          </span>
        )}

        <CaretDownIcon
          size={14}
          weight="bold"
          style={{ color: 'var(--martis-text-muted)', flexShrink: 0 }}
        />
      </button>
      {canShowCreateButton && (
        <button
          type="button"
          onClick={(e) => { e.stopPropagation(); setShowInlineCreate(true) }}
          className="inline-flex items-center justify-center rounded-md border text-sm font-medium transition-colors martis-create-related-btn"
          data-pr-tooltip={tMsg('belongs_to_create_related', { resource: field.label, defaultValue: 'Create' })}
          data-pr-position="top"
          style={{
            borderColor: 'var(--martis-border)',
            backgroundColor: 'var(--martis-surface)',
            color: createButtonColorField ?? 'var(--martis-primary)',
            height: '38px',
            width: '38px',
            flexShrink: 0,
          }}
          onMouseEnter={(e) => { e.currentTarget.style.backgroundColor = 'var(--martis-hover)' }}
          onMouseLeave={(e) => { e.currentTarget.style.backgroundColor = 'var(--martis-surface)' }}
        >
          {createButtonIconField ? (
            <ResourceIcon iconName={createButtonIconField} size={16} color={createButtonColorField ?? undefined} />
          ) : (
            <PlusIcon size={16} weight="bold" />
          )}
        </button>
      )}
      </div>
      {/* Tooltips handled by global Layout <Tooltip> */}

      {/* Dropdown panel */}
      {open && (
        <div className="martis-belongs-to-dropdown">
          {/* Search input */}
          <div className="martis-belongs-to-search">
            <MagnifyingGlassIcon size={14} style={{ color: 'var(--martis-text-muted)', flexShrink: 0 }} />
            <input
              ref={searchInputRef}
              type="text"
              value={search}
              onChange={(e) => handleSearchChange(e.target.value)}
              placeholder={tMsg('belongs_to_search_placeholder', { defaultValue: 'Search…' })}
              className="martis-belongs-to-search-input"
            />
          </div>

          {/* Options list */}
          <div className="martis-belongs-to-options">
            {loading && options.length === 0 ? (
              <div className="martis-belongs-to-empty">{tMsg('loading')}</div>
            ) : !loading && options.length === 0 ? (
              <div className="martis-belongs-to-empty">
                {search
                  ? tMsg('belongs_to_no_results', { defaultValue: 'No results' })
                  : tMsg('no_records_available')}
              </div>
            ) : (
              options.map((record) => {
                const label = getOptionLabel(record)
                const subtitle = getOptionSubtitle(record)
                const isSelected = currentId !== null && String(record.id) === String(currentId)
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
                      <CheckIcon size={14} weight="bold" style={{ color: 'var(--martis-accent)', flexShrink: 0 }} />
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
          showResourceIcon={showResourceIcon}
          resourceIconOverride={resourceIconOverride}
          resourceIconColor={resourceIconColor}
          resourceSubtitle={resourceSubtitleField}
        />
      )}
    </div>
  )
}
