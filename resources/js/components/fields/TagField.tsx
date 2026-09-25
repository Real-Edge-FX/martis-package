import { useState, useEffect, useRef, useCallback } from 'react'
import { useParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { MagnifyingGlassIcon, XIcon, PlusIcon, PlusCircleIcon, CheckIcon } from '@phosphor-icons/react'
import { api } from '@/lib/api'
import { InlineCreateModal } from '@/components/InlineCreateModal'
import { PeekCard } from './BelongsToField'
import type { FieldDisplayProps, FieldInputProps } from './types'
import type { PaginatedResponse } from '@/types'
import { relatedRecordLabel } from '@/lib/relatedRecordLabel'
import { relatableUrl, withQuery } from '@/lib/relatableEndpoint'
import { useEscapeLayer } from '@/lib/escapeLayers'

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

/**
 * A tag that opens the peek card of its record (the related resource's
 * `fieldsForPreview()`) after a short hover, for `withPreview()`.
 */
function PreviewableTag({ resourceKey, recordId, children }: { resourceKey: string; recordId: number | string; children: React.ReactNode }) {
  const ref = useRef<HTMLSpanElement>(null)
  const timer = useRef<ReturnType<typeof setTimeout> | null>(null)
  const [triggerRect, setTriggerRect] = useState<{ top: number; bottom: number; left: number } | null>(null)

  useEffect(() => () => { if (timer.current) clearTimeout(timer.current) }, [])

  function handleMouseEnter() {
    timer.current = setTimeout(() => {
      const rect = ref.current?.getBoundingClientRect()
      if (rect) setTriggerRect({ top: rect.top, bottom: rect.bottom, left: rect.left })
    }, 300)
  }

  function handleMouseLeave() {
    if (timer.current) clearTimeout(timer.current)
    setTriggerRect(null)
  }

  return (
    <span ref={ref} className="inline-flex" onMouseEnter={handleMouseEnter} onMouseLeave={handleMouseLeave}>
      {children}
      {triggerRect && <PeekCard resourceKey={resourceKey} recordId={recordId} triggerRect={triggerRect} />}
    </span>
  )
}

export function TagFieldDisplay({ field, value }: FieldDisplayProps) {
  const tags = toTagArray(value)
  const displayAsList = (field as Record<string, unknown>).displayAsList as boolean | undefined
  const relatedResource = (field as Record<string, unknown>).relatedResource as string | undefined
  const withPreview = (field as Record<string, unknown>).withPreview === true && !!relatedResource

  if (tags.length === 0) {
    return <span className="martis-text-muted">—</span>
  }

  const label = (tag: TagValue) => {
    const text = tag.title ?? String(tag.id)
    return withPreview && relatedResource
      ? <PreviewableTag resourceKey={relatedResource} recordId={tag.id}>{text}</PreviewableTag>
      : text
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
            {label(tag)}
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
          {label(tag)}
        </span>
      ))}
    </div>
  )
}

// ---------------------------------------------------------------------------
// Input — relational tag selector
// ---------------------------------------------------------------------------

export function TagFieldInput({ field, value, onChange, error, resourceKey, recordId, context, actionEndpoint, pivotEndpoint, repeaterRow }: FieldInputProps) {
  const { t: tMsg } = useTranslation('messages')
  const { t: tAct } = useTranslation('actions')
  const relatedResource = (field as Record<string, unknown>).relatedResource as string | undefined
  const titleAttribute = (field as Record<string, unknown>).titleAttribute as string | undefined
  const preload = (field as Record<string, unknown>).preload as boolean | undefined
  // `relationSearchable(false)`: no search box, so the list shows as many
  // options as the relatable endpoint returns (it caps a page at 100).
  const relationSearchable = (field as Record<string, unknown>).relationSearchable !== false
  const perPage = relationSearchable ? 30 : 100
  const showCreateRelationButton = (field as Record<string, unknown>).showCreateRelationButton === true
  const fieldModalSize = ((field as Record<string, unknown>).modalSize as string) || '2xl'
  // A readonly field keeps its tags, so it offers no inline create either.
  const canCreate = showCreateRelationButton && !!relatedResource && !field.readonly

  const [selected, setSelected] = useState<TagValue[]>(() => toTagArray(value))

  // The last value this input handed to `onChange`. A `value` prop that
  // differs from it came from outside (the edit form seeding the stored tags
  // after mount, "Create & add another" clearing the form) and replaces the
  // selection; the form handing back what the input just emitted does not.
  const emitted = useRef<unknown>(value)

  useEffect(() => {
    if (value === emitted.current) return
    emitted.current = value
    setSelected(toTagArray(value))
  }, [value])

  const [open, setOpen] = useState(false)
  const [search, setSearch] = useState('')
  const [options, setOptions] = useState<RelatedRecord[]>([])
  const [loading, setLoading] = useState(false)
  const [showInlineCreate, setShowInlineCreate] = useState(false)

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

  // Escape closes the picker only, not a drawer the form is in.
  useEscapeLayer(open, () => {
    setOpen(false)
    setSearch('')
  })

  // Cleanup debounce timer on unmount
  useEffect(() => {
    return () => {
      if (debounceRef.current) clearTimeout(debounceRef.current)
    }
  }, [])

  // The form (or Action, pivot fields, Repeater row) the picker renders in
  // scopes the relatable endpoint
  const params = useParams<{ resource?: string; id?: string }>()
  const scopedUrl = relatableUrl(field.attribute, { resourceKey, recordId, context, actionEndpoint, pivotEndpoint, repeaterRow }, params)

  const fetchOptions = useCallback(async (query: string) => {
    if (!relatedResource) return

    setLoading(true)
    try {
      const searchParam = query ? `&search=${encodeURIComponent(query)}` : ''
      // Always use relatable endpoint - applies query hooks server-side
      const endpoint = scopedUrl
        ? withQuery(scopedUrl, `per_page=${perPage}${searchParam}`)
        : `/api/resources/_/_/relatable/${field.attribute}?per_page=${perPage}&related_resource=${relatedResource}${searchParam}`
      const res = await api.get<PaginatedResponse<RelatedRecord>>(endpoint)
      setOptions(res.data ?? [])
    } catch {
      setOptions([])
    } finally {
      setLoading(false)
    }
  }, [relatedResource, scopedUrl, field.attribute, perPage])

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
    return relatedRecordLabel(record, titleAttribute)
  }

  function isSelectedId(id: number | string): boolean {
    return selected.some((t) => String(t.id) === String(id))
  }

  function emitChange(next: TagValue[]) {
    emitted.current = next
    setSelected(next)
    onChange(next)
  }

  function toggleTag(record: RelatedRecord) {
    if (field.readonly) return
    const label = getOptionLabel(record)

    if (isSelectedId(record.id)) {
      emitChange(selected.filter((t) => String(t.id) !== String(record.id)))
    } else {
      emitChange([...selected, { id: record.id, title: label }])
    }
  }

  function removeTag(id: number | string) {
    if (field.readonly) return
    emitChange(selected.filter((t) => String(t.id) !== String(id)))
  }

  // The inline-create modal reports the record it created from the render
  // that submitted it, so the selection and the readonly flag are read live
  // when it settles.
  const liveRef = useRef({ selected, readonly: field.readonly })
  liveRef.current = { selected, readonly: field.readonly }

  function openInlineCreate() {
    setOpen(false)
    setSearch('')
    setShowInlineCreate(true)
  }

  function handleInlineCreated(record: { id: string | number; title: string | null }) {
    setShowInlineCreate(false)
    // Preloaded options are fetched once; the others on every open.
    if (preload) void fetchOptions('')
    // A field that turned readonly while the record was being created keeps
    // its tags: the save would drop the new one.
    const { selected: current, readonly } = liveRef.current
    if (readonly || current.some((t) => String(t.id) === String(record.id))) return
    emitChange([...current, { id: record.id, title: record.title ?? String(record.id) }])
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
                  data-pr-tooltip={tMsg('tag_remove', { title: tag.title ?? String(tag.id), defaultValue: `Remove ${tag.title ?? tag.id}` })}
                  data-pr-position="top"
                  className="opacity-70 hover:opacity-100 transition-opacity"
                  style={{ color: 'var(--martis-danger)', lineHeight: 1, background: 'none', border: 'none', cursor: 'pointer', padding: 0 }}
                >
                  <XIcon size={10} weight="bold" />
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
          <PlusIcon size={12} weight="bold" />
          {tMsg('tag_add', { label: field.label, defaultValue: `Add ${field.label}` })}
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
          {relationSearchable && (
            <div
              className="flex items-center gap-2 px-3 py-2"
              style={{ borderBottom: '1px solid var(--martis-border)' }}
            >
              <MagnifyingGlassIcon size={14} style={{ color: 'var(--martis-text-muted)', flexShrink: 0 }} />
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
          )}

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
                      <CheckIcon size={12} weight="bold" style={{ color: 'var(--martis-accent)' }} />
                    )}
                  </button>
                )
              })
            )}
          </div>

          {canCreate && (
            <button
              type="button"
              onClick={openInlineCreate}
              className="w-full text-left flex items-center gap-1.5 text-xs font-medium transition-colors"
              style={{
                padding: '0.5rem 0.75rem',
                color: 'var(--martis-accent)',
                borderTop: '1px solid var(--martis-border)',
              }}
              onMouseEnter={(e) => { e.currentTarget.style.backgroundColor = 'var(--martis-hover)' }}
              onMouseLeave={(e) => { e.currentTarget.style.backgroundColor = 'transparent' }}
            >
              <PlusCircleIcon size={14} weight="bold" />
              {tAct('create')}
            </button>
          )}
        </div>
      )}

      {error && <small className="text-red-500">{error}</small>}

      {canCreate && relatedResource && (
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
