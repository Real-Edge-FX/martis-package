import { useState, useRef, useEffect, useMemo } from 'react'
import { createPortal } from 'react-dom'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { api, ApiError } from '@/lib/api'
import type { PaginatedResponse, ResourceRecord, ResourceSchema, FieldDefinition } from '@/types'
import type { FieldDisplayProps, FieldInputProps } from './types'
import { FieldDisplay, FieldInput } from '@/components/fields/FieldRenderer'
import { nestedErrorsOf } from '@/lib/fieldErrors'
import { useHiddenAttributes, withoutHiddenFields } from '@/lib/hiddenFields'
import { Pagination } from '@/components/Pagination'
import { useModalHistoryLock } from '@/lib/historyLock'
import { useTranslation } from 'react-i18next'
import type { ActionMeta } from '@/components/Actions/ActionModal'
import { PlusIcon, LinkSimpleIcon, LinkBreakIcon, PencilSimpleIcon, MagnifyingGlassIcon, CaretDownIcon, XIcon, LightningIcon } from '@phosphor-icons/react'
import { EditPivotModal } from './BelongsToManyField'
import { useRelationParent } from './NestedParentContext'
import { RelationshipTableShell } from '@/components/fields/relation/RelationshipTableShell'
import { PivotActionModal } from '@/components/fields/relation/PivotActionModal'
import { recordHref } from '@/lib/recordHref'
import { pivotRowActions } from '@/lib/relationRowActions'
import { DataTable } from 'primereact/datatable'
import { Column } from 'primereact/column'
import { useEscapeLayer } from '@/lib/escapeLayers'

// -------------------------------------------------------------------------
// Modal size mapping — PHP ModalSize enum value → CSS max-width
// -------------------------------------------------------------------------

const MODAL_SIZE_MAP: Record<string, string> = {
  sm: '24rem',
  md: '28rem',
  lg: '32rem',
  xl: '36rem',
  '2xl': '42rem',
  '3xl': '48rem',
  '4xl': '56rem',
  '5xl': '64rem',
  '6xl': '72rem',
  '7xl': '80rem',
}

// -------------------------------------------------------------------------
// MorphToMany index display — count badge
// -------------------------------------------------------------------------

export function MorphToManyFieldDisplay({ field, value }: FieldDisplayProps) {
  const { id: parentId } = useRelationParent()

  if (typeof value === 'number') {
    return <MorphToManyCountBadge count={value} />
  }

  // No record to attach to yet: no panel.
  if (!parentId) return null

  // Detail page — render the full panel in read-only mode (no attach/detach/pivot actions)
  return <MorphToManyDetailPanel field={field} />
}

function MorphToManyCountBadge({ count }: { count: number }) {
  return (
    <span
      className="martis-badge"
      style={{
        backgroundColor: 'var(--martis-surface)',
        color: 'var(--martis-text)',
        borderColor: 'var(--martis-border)',
      }}
    >
      <LinkSimpleIcon size={11} />
      {count}
    </span>
  )
}

// -------------------------------------------------------------------------
// MorphToMany detail panel — full table + attach/detach
// -------------------------------------------------------------------------

interface BtmMeta {
  perPage: number
  perPageOptions: number[]
  canAttach: boolean
  canDetach: boolean
  hideSearch?: boolean
  hideCreateButton?: boolean
  hidePerPageSelector?: boolean
  hideEditAction?: boolean
  hideDeleteAction?: boolean
  hideSoftDeleteToggle?: boolean
  hideRestoreAction?: boolean
  hideForceDeleteAction?: boolean
}

function MorphToManyDetailPanel({ field, readOnly = false }: { field: FieldDisplayProps['field']; readOnly?: boolean }) {
  const { t: tAct } = useTranslation('actions')
  const { t: tMsg } = useTranslation('messages')
  const qc = useQueryClient()

  const meta = field.morphToManyMeta as BtmMeta | undefined
  const relationship = field.relationship as string
  const relatedResource = field.relatedResource as string
  const collapsable = !!(field.collapsable as boolean)
  const collapsedByDefault = !!(field.collapsedByDefault as boolean)
  const pivotFields = (field.pivotFields as FieldDefinition[] | undefined) ?? []
  const searchable = !!(field.searchable as boolean)
  const modalSize = (field.modalSize as string | undefined) ?? '2xl'
  const modalHeight = (field.modalHeight as string | undefined) ?? null
  const withSubtitles = !!(field.withSubtitles as boolean | undefined)
  const subtitleAttribute = (field.subtitleAttribute as string | undefined) ?? 'subtitle'

  // The record whose related records this panel lists: the enclosing card's
  // or drawer's record when nested, else the one in the URL.
  const { resource: parentResource, id: parentId } = useRelationParent()

  const [showAttachModal, setShowAttachModal] = useState(false)
  const [detachTarget, setDetachTarget] = useState<{ id: string | number; title?: string } | null>(null)
  // Why the last detach failed (a 403 when the policy denies it, a 500), shown
  // in the confirmation until it closes or the next attempt.
  const [detachError, setDetachError] = useState<string | null>(null)
  const [editTarget, setEditTarget] = useState<{ id: string | number; title?: string; pivot: Record<string, unknown> } | null>(null)
  const [selectedRows, setSelectedRows] = useState<ResourceRecord[]>([])
  const [activePivotAction, setActivePivotAction] = useState<ActionMeta | null>(null)
  // One dropdown per pivot label; only the one clicked opens.
  const [openPivotGroup, setOpenPivotGroup] = useState<string | null>(null)
  const pivotGroupRefs = useRef<Record<string, HTMLDivElement | null>>({})

  useEffect(() => {
    setSelectedRows([])
  }, [parentId, parentResource])

  // Listed here; PivotActionModal reads each action's fields and runs it
  // under the same endpoint, so actions declared on the field resolve too.
  const pivotActionsUrl = `/api/resources/${parentResource}/${parentId}/morph-to-many/${relationship}/actions`

  const pivotActionsQuery = useQuery({
    queryKey: ['pivot-actions', parentResource, parentId, relationship],
    queryFn: ({ signal }) =>
      api.get<{ data: { actions: ActionMeta[] } }>(`${pivotActionsUrl}?context=detail`, signal),
    enabled: !readOnly && !!parentResource && !!parentId && !!relationship,
  })

  const pivotActions = readOnly ? [] : (pivotActionsQuery.data?.data?.actions ?? [])
  const hasPivotActions = pivotActions.length > 0

  useEffect(() => {
    function handleClickOutside(e: MouseEvent) {
      const openGroup = openPivotGroup === null ? null : pivotGroupRefs.current[openPivotGroup]
      if (openGroup && !openGroup.contains(e.target as Node)) {
        setOpenPivotGroup(null)
      }
    }
    if (openPivotGroup !== null) {
      document.addEventListener('mousedown', handleClickOutside)
      return () => document.removeEventListener('mousedown', handleClickOutside)
    }
  }, [openPivotGroup])

  // Escape closes the pivot action group only, not a drawer the panel is in.
  useEscapeLayer(openPivotGroup !== null, () => setOpenPivotGroup(null))

  const detachMutation = useMutation({
    mutationFn: (relatedId: string | number) =>
      api.delete(
        `/api/resources/${parentResource}/${parentId}/morph-to-many/${relationship}/${relatedId}/detach`
      ),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ['morph-to-many', parentResource, parentId, relationship] })
      void qc.invalidateQueries({ queryKey: ['mtm-attachable', parentResource, parentId, relationship] })
      setDetachTarget(null)
      setDetachError(null)
    },
    onError: (e: unknown) => {
      setDetachError(e instanceof ApiError && e.message ? e.message : tMsg('error_detach', 'The record could not be detached.'))
    },
  })

  const pivotActionGroups = pivotActions.reduce<Record<string, ActionMeta[]>>((acc, action) => {
    const label = action.pivotLabel ?? tAct('actions', 'Actions')
    if (!acc[label]) acc[label] = []
    acc[label].push(action)
    return acc
  }, {})

  const showAttachButton = !readOnly && !!meta?.canAttach && !meta?.hideCreateButton
  const { showEditPivot, showDetach, hasAny: showRowActionsExtras } = pivotRowActions({
    readOnly,
    pivotFieldsCount: pivotFields.length,
    canDetach: !!meta?.canDetach,
    hideEditAction: !!meta?.hideEditAction,
    hideDeleteAction: !!meta?.hideDeleteAction,
  })

  return (
    <>
      <RelationshipTableShell
        title={field.label}
        relatedResource={relatedResource}
        collapsable={collapsable}
        collapsedByDefault={collapsedByDefault}
        queryKey={['morph-to-many', parentResource, parentId, relationship]}
        fetchUrl={(params) =>
          `/api/resources/${parentResource}/${parentId}/morph-to-many/${relationship}?${params.toString()}`
        }
        viewUrl={(id) => recordHref(relatedResource, id)}
        pivotFields={pivotFields}
        selectable={hasPivotActions}
        selectedRows={selectedRows}
        onSelectionChange={setSelectedRows}
        perPage={meta?.perPage ?? 10}
        perPageOptions={meta?.perPageOptions ?? [10, 25, 50]}
        searchable={searchable}
        canCreate={false}
        canUpdate={false}
        canDelete={false}
        hideSearch={!!meta?.hideSearch}
        hidePerPageSelector={!!meta?.hidePerPageSelector}
        hideSoftDeleteToggle={!!meta?.hideSoftDeleteToggle}
        hideRestoreAction={!!meta?.hideRestoreAction}
        hideForceDeleteAction={!!meta?.hideForceDeleteAction}
        hideViewAction
        toolbarExtras={({ selectedRows: selected }) => (
          <>
            {hasPivotActions && Object.entries(pivotActionGroups).map(([label, actions]) => (
              <div key={label} className="relative flex-shrink-0" ref={(el) => { pivotGroupRefs.current[label] = el }}>
                <button
                  type="button"
                  onClick={() => setOpenPivotGroup((open) => (open === label ? null : label))}
                  className="inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-sm font-medium flex-shrink-0"
                  style={{
                    backgroundColor: selected.length > 0 ? 'var(--martis-accent)' : 'var(--martis-surface)',
                    color: selected.length > 0 ? '#fff' : 'var(--martis-text)',
                    border: '1px solid var(--martis-border)',
                    cursor: 'pointer',
                  }}
                >
                  <LightningIcon size={14} />
                  {label}
                  {selected.length > 0 && (
                    <span
                      className="inline-flex items-center rounded-full px-1.5 py-0.5 text-xs font-medium"
                      style={{ backgroundColor: 'rgba(255,255,255,0.25)', color: '#fff' }}
                    >
                      {selected.length}
                    </span>
                  )}
                  <CaretDownIcon size={12} />
                </button>
                {openPivotGroup === label && (
                  <div
                    className="absolute left-0 top-full z-50 mt-1 min-w-[180px] overflow-hidden rounded-lg shadow-lg"
                    style={{
                      backgroundColor: 'var(--martis-card)',
                      border: '1px solid var(--martis-border)',
                    }}
                  >
                    {actions.map((action) => (
                      <button
                        key={action.uriKey}
                        type="button"
                        disabled={selected.length === 0 && !action.standalone}
                        onClick={() => {
                          setOpenPivotGroup(null)
                          setActivePivotAction(action)
                        }}
                        className="flex w-full items-center gap-2 px-4 py-2.5 text-sm transition-colors disabled:opacity-40 disabled:cursor-not-allowed"
                        style={{
                          color: action.destructive ? 'var(--martis-danger)' : 'var(--martis-text)',
                          background: 'none',
                          border: 'none',
                          cursor: selected.length === 0 && !action.standalone ? 'not-allowed' : 'pointer',
                          textAlign: 'left',
                        }}
                        onMouseEnter={(e) => {
                          if (selected.length > 0 || action.standalone)
                            e.currentTarget.style.backgroundColor = 'var(--martis-surface)'
                        }}
                        onMouseLeave={(e) => {
                          e.currentTarget.style.backgroundColor = 'transparent'
                        }}
                      >
                        <LightningIcon size={14} style={{ color: action.destructive ? 'var(--martis-danger)' : 'var(--martis-accent)' }} />
                        {action.name}
                      </button>
                    ))}
                  </div>
                )}
              </div>
            ))}
            {showAttachButton && (
              <button
                type="button"
                onClick={() => setShowAttachModal(true)}
                className="inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-sm font-medium text-martis-accent-contrast flex-shrink-0"
                style={{ backgroundColor: 'var(--martis-accent)' }}
              >
                <PlusIcon size={14} weight="bold" />
                {tAct('attach', 'Attach')}
              </button>
            )}
          </>
        )}
        rowActionsExtras={showRowActionsExtras ? (row) => (
          <>
            {showEditPivot && (
              <button
                type="button"
                onClick={() => setEditTarget({
                  id: row.id as string | number,
                  title: row._title as string,
                  pivot: (row._pivot as Record<string, unknown>) ?? {},
                })}
                className="rounded p-1.5 transition-colors"
                style={{ color: 'var(--martis-text-muted)', background: 'none', border: 'none', cursor: 'pointer' }}
                data-pr-tooltip={tAct('edit', 'Edit')}
                data-pr-position="top"
                onMouseEnter={(e) => (e.currentTarget.style.color = 'var(--martis-accent)')}
                onMouseLeave={(e) => (e.currentTarget.style.color = 'var(--martis-text-muted)')}
              >
                <PencilSimpleIcon size={16} />
              </button>
            )}
            {showDetach && (
              <button
                type="button"
                onClick={() => setDetachTarget({ id: row.id as string | number, title: row._title as string })}
                className="inline-flex items-center gap-1 rounded px-2 py-1 text-xs transition-colors"
                style={{ color: 'var(--martis-text-muted)', background: 'none', border: '1px solid var(--martis-border)', cursor: 'pointer' }}
                data-pr-tooltip={tAct('detach', 'Detach')}
                data-pr-position="top"
                onMouseEnter={(e) => {
                  e.currentTarget.style.color = 'var(--martis-danger)'
                  e.currentTarget.style.borderColor = 'var(--martis-danger)'
                }}
                onMouseLeave={(e) => {
                  e.currentTarget.style.color = 'var(--martis-text-muted)'
                  e.currentTarget.style.borderColor = 'var(--martis-border)'
                }}
              >
                <LinkBreakIcon size={14} />
                {tAct('detach', 'Detach')}
              </button>
            )}
          </>
        ) : undefined}
      />

      {/* Detach confirmation */}
      {detachTarget && (
        <DetachConfirmModal
          title={detachTarget.title ?? String(detachTarget.id)}
          onConfirm={() => { setDetachError(null); detachMutation.mutate(detachTarget.id) }}
          onCancel={() => { setDetachTarget(null); setDetachError(null) }}
          loading={detachMutation.isPending}
          error={detachError}
        />
      )}

      {/* Edit pivot modal — opens for an attached row to update its pivot data. */}
      {editTarget && (
        <EditPivotModal
          title={editTarget.title ?? String(editTarget.id)}
          endpoint={`/api/resources/${parentResource}/${parentId}/morph-to-many/${relationship}/${editTarget.id}/pivot`}
          pivotEndpoint={`/api/resources/${parentResource}/${parentId}/morph-to-many/${relationship}/pivot-fields/${editTarget.id}`}
          pivotFields={pivotFields}
          initialValues={editTarget.pivot}
          onSuccess={() => {
            setEditTarget(null)
            void qc.invalidateQueries({ queryKey: ['morph-to-many', parentResource, parentId, relationship] })
          }}
          onCancel={() => setEditTarget(null)}
        />
      )}

      {/* Pivot action modal */}
      {activePivotAction && (
        <PivotActionModal
          actionsUrl={pivotActionsUrl}
          action={activePivotAction}
          selectedIds={selectedRows.map((r) => r.id as string | number)}
          onSuccess={() => {
            setActivePivotAction(null)
            setSelectedRows([])
            void qc.invalidateQueries({ queryKey: ['morph-to-many', parentResource, parentId, relationship] })
          }}
          onClose={() => setActivePivotAction(null)}
        />
      )}

      {/* Attach modal */}
      {showAttachModal && (
        <AttachModal
          parentResource={parentResource}
          parentId={parentId}
          relationship={relationship}
          relatedResource={relatedResource}
          pivotFields={pivotFields}
          modalSize={modalSize}
          modalHeight={modalHeight}
          withSubtitles={withSubtitles}
          subtitleAttribute={subtitleAttribute}
          onSuccess={() => {
            setShowAttachModal(false)
            void qc.invalidateQueries({ queryKey: ['morph-to-many', parentResource, parentId, relationship] })
          }}
          onClose={() => setShowAttachModal(false)}
        />
      )}
    </>
  )
}


// -------------------------------------------------------------------------
// Detach confirmation modal
// -------------------------------------------------------------------------

function DetachConfirmModal({
  title,
  onConfirm,
  onCancel,
  loading,
  error,
}: {
  title: string
  onConfirm: () => void
  onCancel: () => void
  loading: boolean
  error?: string | null
}) {
  const { t: tAct } = useTranslation('actions')
  const { t: tMsg } = useTranslation('messages')

  useModalHistoryLock(true)

  return createPortal((
    <div
      className="martis-modal-scrim"
      onClick={onCancel}
    >
      <div
        role="dialog"
        aria-modal="true"
        className="martis-modal-surface"
        onClick={(e) => e.stopPropagation()}
      >
        <div className="martis-modal-head">
          <div className="flex items-center gap-3">
            <LinkBreakIcon size={18} weight="bold" style={{ color: 'var(--martis-danger)' }} />
            <h3 className="martis-modal-head-title">
              {tAct('detach', 'Detach')} {title ? `"${title}"` : ''}
            </h3>
          </div>
          <button
            type="button"
            onClick={onCancel}
            className="martis-modal-close"
            aria-label={tAct('cancel', 'Cancel')}
          >
            <XIcon size={16} />
          </button>
        </div>

        <div className="martis-modal-body">
          {tMsg('detach_confirm', 'This record will be detached from the relationship. No data will be deleted. Continue?')}
          {error && (
            <p role="alert" className="mt-3 text-sm" style={{ color: 'var(--martis-danger)' }}>{error}</p>
          )}
        </div>

        <div className="martis-modal-foot">
          <button type="button" onClick={onCancel} disabled={loading} className="martis-btn-secondary">
            <XIcon size={14} />
            {tAct('cancel', 'Cancel')}
          </button>
          <button
            type="button"
            disabled={loading}
            onClick={onConfirm}
            className="martis-btn-danger"
          >
            <LinkBreakIcon size={14} />
            {loading ? tAct('please_wait', 'Please wait…') : tAct('detach', 'Detach')}
          </button>
        </div>
      </div>
    </div>
  ), document.body)
}

// -------------------------------------------------------------------------
// Debounce hook
// -------------------------------------------------------------------------

function useDebounce<T>(value: T, delay: number): T {
  const [debouncedValue, setDebouncedValue] = useState(value)
  const timeoutRef = useRef<ReturnType<typeof setTimeout> | null>(null)

  useEffect(() => {
    timeoutRef.current = setTimeout(() => setDebouncedValue(value), delay)
    return () => {
      if (timeoutRef.current) clearTimeout(timeoutRef.current)
    }
  }, [value, delay])

  return debouncedValue
}

// -------------------------------------------------------------------------
// Attach modal — DataTable with multi-select + search + pagination
// -------------------------------------------------------------------------

function AttachModal({
  parentResource,
  parentId,
  relationship,
  relatedResource,
  pivotFields,
  modalSize = '2xl',
  modalHeight,
  withSubtitles = false,
  subtitleAttribute = 'subtitle',
  onSuccess,
  onClose,
}: {
  parentResource: string
  parentId: string
  relationship: string
  relatedResource: string
  pivotFields: FieldDefinition[]
  modalSize?: string
  modalHeight?: string | null
  withSubtitles?: boolean
  subtitleAttribute?: string
  onSuccess: () => void
  onClose: () => void
}) {
  const { t: tAct } = useTranslation('actions')
  const { t: tMsg } = useTranslation('messages')
  const { t: tRes } = useTranslation('resources')

  useModalHistoryLock(true)

  const [search, setSearch] = useState('')
  const debouncedSearch = useDebounce(search, 300)
  const [selected, setSelected] = useState<ResourceRecord[]>([])
  const [pivotValues, setPivotValues] = useState<Record<string, unknown>>(() => {
    const defaults: Record<string, unknown> = {}
    for (const pf of pivotFields) {
      if (pf.defaultValue != null) defaults[pf.attribute] = pf.defaultValue
    }
    return defaults
  })
  const [error, setError] = useState<string | null>(null)
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({})
  const [attachPage, setAttachPage] = useState(1)
  const [attachPerPage, setAttachPerPage] = useState(15)

  const perPageOptions = [10, 15, 25, 50]

  // Fetch related resource schema for DataTable columns
  const schemaQuery = useQuery({
    queryKey: ['schema', relatedResource],
    queryFn: () => api.get<{ data: ResourceSchema }>(`/api/resources/${relatedResource}/schema`),
    enabled: !!relatedResource,
  })

  const attachableQuery = useQuery({
    queryKey: ['mtm-attachable', parentResource, parentId, relationship, debouncedSearch, attachPage, attachPerPage],
    queryFn: () => {
      const params = new URLSearchParams({ per_page: String(attachPerPage), page: String(attachPage) })
      if (debouncedSearch) params.set('search', debouncedSearch)
      return api.get<PaginatedResponse<ResourceRecord>>(
        `/api/resources/${parentResource}/${parentId}/morph-to-many/${relationship}/attachable?${params.toString()}`
      )
    },
    enabled: !!parentResource && !!parentId && !!relationship,
  })

  const attachMutation = useMutation({
    mutationFn: (payload: Record<string, unknown>) =>
      api.post(
        `/api/resources/${parentResource}/${parentId}/morph-to-many/${relationship}/attach`,
        payload
      ),
    onSuccess: () => { onSuccess() },
    onError: (e: unknown) => {
      if (e instanceof ApiError) {
        const byField = e.errorsByField()
        setFieldErrors(byField)
        setError(Object.keys(byField).length === 0 ? e.message : null)
      } else {
        setFieldErrors({})
        setError((e as { message?: string })?.message ?? 'Failed to attach.')
      }
    },
  })

  const records = attachableQuery.data?.data ?? []
  const pagination = attachableQuery.data?.meta
  // The pivot fields a new row hides (`canSeeForModel()` on the row the
  // attach writes): the attachable list names them, and the modal leaves them
  // out; the attach stores their `default()` whatever the form sends.
  const attachHidden = useHiddenAttributes({
    _hidden: (attachableQuery.data?.meta as { hiddenPivotFields?: unknown } | undefined)?.hiddenPivotFields,
  })
  const shownPivotFields = useMemo(() => withoutHiddenFields(pivotFields, attachHidden), [pivotFields, attachHidden])
  const schema = schemaQuery.data?.data
  const indexFields: FieldDefinition[] = schema?.fieldsForIndex ?? []

  function handleAttach() {
    if (selected.length === 0) return
    setError(null)
    setFieldErrors({})
    if (selected.length === 1) {
      const payload: Record<string, unknown> = { related_id: selected[0].id, ...pivotValues }
      attachMutation.mutate(payload)
    } else {
      const payload: Record<string, unknown> = { related_ids: selected.map((s) => s.id), ...pivotValues }
      attachMutation.mutate(payload)
    }
  }

  const modalMaxWidth = MODAL_SIZE_MAP[modalSize] ?? MODAL_SIZE_MAP['2xl']

  return createPortal((
    <div
      className="martis-modal-scrim"
      onClick={onClose}
    >
      <div
        role="dialog"
        aria-modal="true"
        className="martis-modal-surface"
        style={{ maxWidth: modalMaxWidth, maxHeight: modalHeight ?? '85vh' }}
        onClick={(e) => e.stopPropagation()}
      >
        <div className="martis-modal-head">
          <div className="flex items-center gap-2">
            <h3 className="martis-modal-head-title">
              {tAct('attach_related', 'Attach Record')}
            </h3>
            {selected.length > 0 && (
              <span
                className="martis-badge"
                style={{ backgroundColor: 'var(--martis-accent)', color: 'var(--martis-accent-contrast, #ffffff)', borderColor: 'transparent' }}
              >
                {selected.length}
              </span>
            )}
          </div>
          <button
            type="button"
            onClick={onClose}
            className="martis-modal-close"
            aria-label={tAct('cancel', 'Cancel')}
          >
            <XIcon size={16} />
          </button>
        </div>

        {/* Search + Per Page — same layout as ResourceIndex */}
        <div className="shrink-0 border-b px-6 py-3" style={{ borderColor: 'var(--martis-border)' }}>
          <div className="flex items-center gap-3">
            <div className="relative flex-1">
              <span className="absolute inset-y-0 left-3 flex items-center pointer-events-none">
                <MagnifyingGlassIcon size={14} style={{ color: 'var(--martis-text-muted)' }} />
              </span>
              <input
                type="text"
                value={search}
                onChange={(e) => { setSearch(e.target.value); setAttachPage(1) }}
                placeholder={tMsg('search', 'Search…')}
                className="martis-resource-search block w-full rounded-md py-2 pl-9 pr-8 text-sm focus:outline-none focus:ring-1"
                style={{
                  backgroundColor: 'var(--martis-input-bg)',
                  border: '1px solid var(--martis-border)',
                  color: 'var(--martis-text)',
                }}
                autoFocus
              />
              {search && (
                <button
                  type="button"
                  onClick={() => { setSearch(''); setAttachPage(1) }}
                  className="absolute inset-y-0 right-2 flex items-center"
                  style={{ cursor: 'pointer', background: 'none', border: 'none' }}
                  data-pr-tooltip={tMsg('clear', 'Clear')}
                  data-pr-position="top"
                >
                  <XIcon size={14} weight="bold" style={{ color: 'var(--martis-danger)' }} />
                </button>
              )}
            </div>
            <div className="flex items-center gap-2 flex-shrink-0">
              <label className="text-xs martis-text-muted whitespace-nowrap">{tRes('per_page', 'Per page')}:</label>
              <select
                value={attachPerPage}
                onChange={(e) => { setAttachPerPage(Number(e.target.value)); setAttachPage(1) }}
                className="martis-perpage-select"
              >
                {perPageOptions.map((opt) => (
                  <option key={opt} value={opt}>{opt}</option>
                ))}
              </select>
            </div>
          </div>
        </div>

        {/* DataTable — scrollable body */}
        <div className="min-h-0 flex-1 overflow-auto px-6">
          <DataTable
            value={records}
            loading={attachableQuery.isLoading}
            dataKey="id"
            selectionMode="multiple"
            selection={selected}
            onSelectionChange={(e) => setSelected(e.value as ResourceRecord[])}
            emptyMessage={
              <div className="py-8 text-center text-sm" style={{ color: 'var(--martis-text-muted)' }}>
                {tMsg('no_records_available', 'No records available.')}
              </div>
            }
            className="w-full martis-datatable martis-datatable-striped"
            tableClassName="min-w-full"
          >
            <Column selectionMode="multiple" headerStyle={{ width: '3rem' }} />
            {indexFields.map((f, idx) => (
              <Column
                key={f.attribute}
                field={f.attribute}
                header={
                  <span className="text-xs font-medium uppercase tracking-wider text-gray-500">
                    {f.label}
                  </span>
                }
                body={(row: ResourceRecord) => (
                  <div>
                    <FieldDisplay field={f} value={row[f.attribute]} resourceKey={relatedResource} />
                    {withSubtitles && idx === 0 && row[subtitleAttribute] != null && (
                      <div className="text-xs mt-0.5" style={{ color: 'var(--martis-text-muted)' }}>
                        {String(row[subtitleAttribute])}
                      </div>
                    )}
                  </div>
                )}
              />
            ))}
          </DataTable>
        </div>

        {/* Pagination — identical to ResourceIndex */}
        {pagination && (
          <div className="shrink-0 px-6 py-2">
            <Pagination
              currentPage={pagination.current_page}
              lastPage={pagination.last_page}
              total={pagination.total}
              perPage={pagination.per_page ?? attachPerPage}
              from={pagination.from}
              to={pagination.to}
              onPageChange={setAttachPage}
            />
          </div>
        )}

        {/* Pivot fields (if any) */}
        {shownPivotFields.length > 0 && selected.length > 0 && (
          <div
            className="shrink-0 space-y-4 border-t px-6 py-4"
            style={{ borderColor: 'var(--martis-border)' }}
          >
            <p className="text-xs font-medium uppercase tracking-wider" style={{ color: 'var(--martis-text-muted)' }}>
              {tAct('pivot_fields', 'Pivot Fields')}
            </p>
            {shownPivotFields.map((pf) => {
              const isRequired = !!(pf as unknown as { required?: boolean }).required
              const fieldError = fieldErrors[pf.attribute]
              return (
                <div key={pf.attribute}>
                  <label className="mb-1 block text-sm font-medium" style={{ color: 'var(--martis-text)' }}>
                    {pf.label}
                    {isRequired && <span className="ml-1" style={{ color: 'var(--martis-danger)' }}>*</span>}
                  </label>
                  <FieldInput
                    field={pf}
                    value={pivotValues[pf.attribute] ?? null}
                    onChange={(v) => setPivotValues((prev) => ({ ...prev, [pf.attribute]: v }))}
                    context="create"
                    // The parent's forms do not declare pivot fields: the
                    // relation pickers ask the panel.
                    pivotEndpoint={`/api/resources/${parentResource}/${parentId}/morph-to-many/${relationship}/pivot-fields`}
                    nestedErrors={nestedErrorsOf(fieldErrors, pf.attribute)}
                  />
                  {fieldError && (
                    <p className="mt-1 text-xs" style={{ color: 'var(--martis-danger)' }}>{fieldError}</p>
                  )}
                </div>
              )
            })}
          </div>
        )}

        {/* Error */}
        {error && (
          <div
            className="shrink-0 mx-6 mb-3 rounded-lg px-4 py-3 text-sm"
            style={{
              border: '1px solid color-mix(in srgb, var(--martis-danger) 30%, transparent)',
              backgroundColor: 'color-mix(in srgb, var(--martis-danger) 10%, transparent)',
              color: 'var(--martis-danger)',
            }}
          >
            {error}
          </div>
        )}

        <div className="martis-modal-foot">
          <button type="button" onClick={onClose} className="martis-btn-secondary">
            <XIcon size={14} />
            {tAct('cancel', 'Cancel')}
          </button>
          <button
            type="button"
            disabled={selected.length === 0 || attachMutation.isPending}
            onClick={handleAttach}
            className="martis-btn-primary"
          >
            <LinkSimpleIcon size={14} />
            {attachMutation.isPending
              ? tAct('please_wait', 'Please wait…')
              : selected.length > 1
                ? `${tAct('attach', 'Attach')} (${selected.length})`
                : tAct('attach', 'Attach')}
          </button>
        </div>
      </div>
    </div>
  ), document.body)
}

// -------------------------------------------------------------------------
// Forms — the panel on the update form; none before the record exists
// -------------------------------------------------------------------------

export function MorphToManyFieldInput({ field }: FieldInputProps) {
  // A create surface names no record (the schema keeps this field off its
  // forms): the panel would read another record, or none.
  const { id: parentId } = useRelationParent()
  if (!parentId) return null

  return <MorphToManyDetailPanel field={field} />
}
