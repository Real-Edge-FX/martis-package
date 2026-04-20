import { useState, useRef, useEffect, useCallback } from 'react'
import { createPortal } from 'react-dom'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { useParams } from 'react-router-dom'
import { api, ApiError } from '@/lib/api'
import type { PaginatedResponse, ResourceRecord, ResourceSchema, FieldDefinition } from '@/types'
import type { FieldDisplayProps, FieldInputProps } from './types'
import { FieldDisplay, FieldInput } from '@/components/fields/FieldRenderer'
import { Pagination } from '@/components/Pagination'
import { RelationshipTableShell } from '@/components/fields/relation/RelationshipTableShell'
import { useModalHistoryLock } from '@/lib/historyLock'
import { useTranslation } from 'react-i18next'
import { useToast } from '@/contexts/ToastContext'
import type { ActionMeta } from '@/components/Actions/ActionModal'
import { PlusIcon, LinkSimpleIcon, LinkBreakIcon, PencilSimpleIcon, MagnifyingGlassIcon, CaretDownIcon, XIcon, LightningIcon, FloppyDiskIcon } from '@phosphor-icons/react'
import { DataTable } from 'primereact/datatable'
import { Column } from 'primereact/column'

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
// BelongsToMany index display — count badge
// -------------------------------------------------------------------------

export function BelongsToManyFieldDisplay({ field, value }: FieldDisplayProps) {
  if (typeof value === 'number') {
    return <BelongsToManyCountBadge count={value} />
  }

  // Detail page — render the full panel with attach/detach/pivot actions.
  // Programmers hide individual actions via ->hideCreateButton()
  // or ->hideDeleteAction() when authorization alone isn't enough.
  return <BelongsToManyDetailPanel field={field} />
}

function BelongsToManyCountBadge({ count }: { count: number }) {
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
// BelongsToMany detail panel — full table + attach/detach
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
}

function BelongsToManyDetailPanel({ field, readOnly = false }: { field: FieldDisplayProps['field']; readOnly?: boolean }) {
  const { t: tAct } = useTranslation('actions')
  const qc = useQueryClient()

  const meta = field.belongsToManyMeta as BtmMeta | undefined
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

  const { resource: parentResource = '', id: parentId = '' } = useParams<{ resource?: string; id?: string }>()

  const [showAttachModal, setShowAttachModal] = useState(false)
  const [detachTarget, setDetachTarget] = useState<{ id: string | number; title?: string } | null>(null)
  const [editTarget, setEditTarget] = useState<{ id: string | number; title?: string; pivot: Record<string, unknown> } | null>(null)
  const [selectedRows, setSelectedRows] = useState<ResourceRecord[]>([])
  const [activePivotAction, setActivePivotAction] = useState<ActionMeta | null>(null)
  const [pivotDropdownOpen, setPivotDropdownOpen] = useState(false)
  const pivotDropdownRef = useRef<HTMLDivElement>(null)

  // Clear selection when parent record changes — stale IDs would confuse pivot actions.
  useEffect(() => {
    setSelectedRows([])
  }, [parentId, parentResource])

  const pivotActionsQuery = useQuery({
    queryKey: ['pivot-actions', parentResource, parentId, relationship],
    queryFn: ({ signal }) =>
      api.get<{ data: { actions: ActionMeta[] } }>(
        `/api/resources/${parentResource}/${parentId}/belongs-to-many/${relationship}/actions?context=detail`,
        signal
      ),
    enabled: !readOnly && !!parentResource && !!parentId && !!relationship,
  })

  const pivotActions = readOnly ? [] : (pivotActionsQuery.data?.data?.actions ?? [])
  const hasPivotActions = pivotActions.length > 0

  useEffect(() => {
    function handleClickOutside(e: MouseEvent) {
      if (pivotDropdownRef.current && !pivotDropdownRef.current.contains(e.target as Node)) {
        setPivotDropdownOpen(false)
      }
    }
    if (pivotDropdownOpen) {
      document.addEventListener('mousedown', handleClickOutside)
      return () => document.removeEventListener('mousedown', handleClickOutside)
    }
  }, [pivotDropdownOpen])

  const detachMutation = useMutation({
    mutationFn: (relatedId: string | number) =>
      api.delete(
        `/api/resources/${parentResource}/${parentId}/belongs-to-many/${relationship}/${relatedId}/detach`
      ),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ['belongs-to-many', parentResource, parentId, relationship] })
      void qc.invalidateQueries({ queryKey: ['btm-attachable', parentResource, parentId, relationship] })
      setDetachTarget(null)
    },
  })

  const pivotActionGroups = pivotActions.reduce<Record<string, ActionMeta[]>>((acc, action) => {
    const label = action.pivotLabel ?? tAct('actions', 'Actions')
    if (!acc[label]) acc[label] = []
    acc[label].push(action)
    return acc
  }, {})

  const showAttachButton = !readOnly && !!meta?.canAttach && !meta?.hideCreateButton
  const showEditPivot = !readOnly && pivotFields.length > 0 && !meta?.hideEditAction
  const showDetach = !readOnly && !!meta?.canDetach && !meta?.hideDeleteAction

  return (
    <>
      <RelationshipTableShell
        title={field.label}
        relatedResource={relatedResource}
        collapsable={collapsable}
        collapsedByDefault={collapsedByDefault}
        queryKey={['belongs-to-many', parentResource, parentId, relationship]}
        fetchUrl={(params) =>
          `/api/resources/${parentResource}/${parentId}/belongs-to-many/${relationship}?${params.toString()}`
        }
        viewUrl={(id) => `/resources/${relatedResource}/${id}`}
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
        hideViewAction
        toolbarExtras={({ selectedRows: selected }) => (
          <>
            {/* Pivot action dropdowns — one per label group */}
            {hasPivotActions && Object.entries(pivotActionGroups).map(([label, actions]) => (
              <div key={label} className="relative flex-shrink-0" ref={pivotDropdownRef}>
                <button
                  type="button"
                  onClick={() => setPivotDropdownOpen((o) => !o)}
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
                {pivotDropdownOpen && (
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
                          setPivotDropdownOpen(false)
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
                className="inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-sm font-medium text-white flex-shrink-0"
                style={{ backgroundColor: 'var(--martis-accent)' }}
              >
                <PlusIcon size={14} weight="bold" />
                {tAct('attach', 'Attach')}
              </button>
            )}
          </>
        )}
        rowActionsExtras={(row) => (
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
                onMouseEnter={(e) => (e.currentTarget.style.color = 'var(--martis-primary)')}
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
        )}
      />

      {/* Detach confirmation */}
      {detachTarget && (
        <DetachConfirmModal
          title={detachTarget.title ?? String(detachTarget.id)}
          onConfirm={async () => { await detachMutation.mutateAsync(detachTarget.id) }}
          onCancel={() => setDetachTarget(null)}
          loading={detachMutation.isPending}
        />
      )}

      {/* Edit pivot modal — opens for an attached row to update its pivot data. */}
      {editTarget && (
        <EditPivotModal
          title={editTarget.title ?? String(editTarget.id)}
          endpoint={`/api/resources/${parentResource}/${parentId}/belongs-to-many/${relationship}/${editTarget.id}/pivot`}
          pivotFields={pivotFields}
          initialValues={editTarget.pivot}
          onSuccess={() => {
            setEditTarget(null)
            void qc.invalidateQueries({ queryKey: ['belongs-to-many', parentResource, parentId, relationship] })
          }}
          onCancel={() => setEditTarget(null)}
        />
      )}

      {/* Pivot action modal */}
      {activePivotAction && (
        <PivotActionModal
          parentResource={parentResource}
          parentId={parentId}
          relationship={relationship}
          action={activePivotAction}
          selectedIds={selectedRows.map((r) => r.id as string | number)}
          onSuccess={() => {
            setActivePivotAction(null)
            setSelectedRows([])
            void qc.invalidateQueries({ queryKey: ['belongs-to-many', parentResource, parentId, relationship] })
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
            void qc.invalidateQueries({ queryKey: ['belongs-to-many', parentResource, parentId, relationship] })
          }}
          onClose={() => setShowAttachModal(false)}
        />
      )}
    </>
  )
}


// -------------------------------------------------------------------------
// Pivot Action Modal
// -------------------------------------------------------------------------

function PivotActionModal({
  parentResource,
  parentId,
  relationship,
  action,
  selectedIds,
  onSuccess,
  onClose,
}: {
  parentResource: string
  parentId: string
  relationship: string
  action: ActionMeta
  selectedIds: Array<string | number>
  onSuccess: () => void
  onClose: () => void
}) {
  const { t } = useTranslation('actions')
  const { addToast } = useToast()

  useModalHistoryLock(true)

  const [fieldValues, setFieldValues] = useState<Record<string, unknown>>({})
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({})
  const [animVisible, setAnimVisible] = useState(false)
  const autoExecuted = useRef(false)

  useEffect(() => {
    requestAnimationFrame(() => setAnimVisible(true))
    autoExecuted.current = false
  }, [])

  const handleBackdropClose = useCallback(() => {
    setAnimVisible(false)
    setTimeout(onClose, 200)
  }, [onClose])

  useEffect(() => {
    function handleKey(e: KeyboardEvent) {
      if (e.key === 'Escape') onClose()
    }
    document.addEventListener('keydown', handleKey)
    return () => document.removeEventListener('keydown', handleKey)
  }, [onClose])

  const fieldsQuery = useQuery({
    queryKey: ['action-fields', parentResource, action.uriKey],
    queryFn: ({ signal }) =>
      api.get<{ data: { fields: FieldDefinition[] } }>(
        `/api/resources/${parentResource}/actions/${action.uriKey}/fields`,
        signal
      ),
    enabled: !!action,
  })

  const fields = fieldsQuery.data?.data?.fields ?? []

  const executeMutation = useMutation({
    mutationFn: () =>
      api.post<{ data: { type: string; data: Record<string, unknown> } }>(
        `/api/resources/${parentResource}/${parentId}/belongs-to-many/${relationship}/actions/${action.uriKey}`,
        {
          resources: selectedIds,
          fields: fieldValues,
        }
      ),
    onSuccess: (res) => {
      const responseData = res?.data
      if (responseData) {
        const data = responseData.data
        switch (responseData.type) {
          case 'message':
            addToast('success', (data?.message as string) ?? t('action_success'))
            break
          case 'danger':
            addToast('error', (data?.message as string) ?? t('action_failed'))
            break
          default:
            addToast('success', t('action_success'))
        }
      } else {
        addToast('success', t('action_success'))
      }
      onSuccess()
    },
    onError: (err: Error) => {
      if (err instanceof ApiError && err.errors && err.errors.length > 0) {
        const mapped: Record<string, string> = {}
        for (const e of err.errors) {
          const fieldKey = e.field.replace(/^fields\./, '')
          if (!mapped[fieldKey]) mapped[fieldKey] = e.message
        }
        if (Object.keys(mapped).length > 0) {
          setFieldErrors(mapped)
          addToast('error', err.message || t('action_failed'))
          return
        }
      }
      addToast('error', (err instanceof ApiError ? err.message : err.message) ?? t('action_failed'))
    },
  })

  const hasFields = fields.length > 0
  const needsConfirmation = action.withConfirmation || hasFields

  // Auto-execute if no confirmation or fields needed (only once)
  if (!needsConfirmation && !autoExecuted.current && !executeMutation.isPending) {
    autoExecuted.current = true
    setTimeout(() => executeMutation.mutate(), 0)
    return null
  }

  if (!needsConfirmation) return null

  const modalWidth = MODAL_SIZE_MAP[action.modalSize ?? 'md'] ?? MODAL_SIZE_MAP['md']

  return (
    <div
      style={{ position: 'fixed', inset: 0, zIndex: 9990 }}
      className="flex items-center justify-center"
    >
      <div
        className="absolute inset-0 transition-opacity duration-200"
        style={{ backgroundColor: animVisible ? 'rgba(0,0,0,0.4)' : 'rgba(0,0,0,0)' }}
        onClick={handleBackdropClose}
      />
      <div
        role="dialog"
        className="relative w-full rounded-xl shadow-xl transition-all duration-200 mx-4"
        style={{
          backgroundColor: 'var(--martis-card)',
          border: action.destructive ? '1px solid rgba(220,38,38,0.4)' : '1px solid var(--martis-border)',
          borderTop: action.destructive ? '3px solid var(--martis-danger)' : undefined,
          maxWidth: modalWidth,
          transform: animVisible ? 'scale(1)' : 'scale(0.95)',
          opacity: animVisible ? 1 : 0,
        }}
      >
        {/* Header */}
        <div
          className="flex items-center justify-between border-b px-6 py-4"
          style={{
            borderColor: action.destructive ? 'rgba(220,38,38,0.2)' : 'var(--martis-border)',
            backgroundColor: action.destructive ? 'rgba(220,38,38,0.05)' : undefined,
          }}
        >
          <div className="flex items-center gap-3">
            <div
              className="flex h-10 w-10 items-center justify-center rounded-full"
              style={{
                backgroundColor: action.destructive ? 'rgba(220,38,38,0.1)' : 'rgba(99,102,241,0.1)',
                color: action.destructive ? 'var(--martis-danger-hover)' : 'var(--martis-accent)',
              }}
            >
              <LightningIcon size={20} weight="fill" />
            </div>
            <span className="text-lg font-semibold" style={{ color: 'var(--martis-text)' }}>
              {action.name}
            </span>
          </div>
          <button
            type="button"
            onClick={onClose}
            className="rounded-md p-1.5 transition-colors hover:bg-gray-100 dark:hover:bg-gray-800"
            style={{ color: 'var(--martis-text-muted)' }}
          >
            <XIcon size={16} />
          </button>
        </div>

        {/* Body */}
        <div className="px-6 py-4">
          {action.confirmText && (
            <p className="mb-4 text-sm" style={{ color: 'var(--martis-text-muted)' }}>
              {action.confirmText}
            </p>
          )}
          {hasFields && (
            <div className="space-y-4">
              {fields.map((f) => (
                <div key={f.attribute}>
                  <label className="mb-1 block text-sm font-medium" style={{ color: 'var(--martis-text)' }}>
                    {f.label}
                    {f.required && <span className="ml-1 text-red-500">*</span>}
                  </label>
                  <FieldInput
                    field={f}
                    value={fieldValues[f.attribute] ?? ''}
                    onChange={(val: unknown) =>
                      setFieldValues((prev) => ({ ...prev, [f.attribute]: val }))
                    }
                    error={fieldErrors[f.attribute]}
                    context="create"
                  />
                </div>
              ))}
            </div>
          )}
        </div>

        {/* Footer */}
        <div
          className="flex items-center justify-end gap-3 border-t px-6 py-4"
          style={{
            borderColor: 'var(--martis-border)',
            backgroundColor: 'var(--martis-surface)',
            borderRadius: '0 0 0.75rem 0.75rem',
          }}
        >
          <button
            type="button"
            onClick={onClose}
            disabled={executeMutation.isPending}
            className="inline-flex items-center gap-2 rounded-lg border px-4 py-2 text-sm font-medium transition-colors hover:opacity-90 disabled:opacity-50"
            style={{
              backgroundColor: 'var(--martis-input-bg)',
              borderColor: 'var(--martis-border)',
              color: 'var(--martis-text)',
            }}
          >
            <XIcon size={14} />
            {action.cancelButtonText ?? t('cancel')}
          </button>
          <button
            type="button"
            onClick={() => executeMutation.mutate()}
            disabled={executeMutation.isPending}
            className="inline-flex items-center gap-2 rounded-lg px-4 py-2 text-sm font-medium text-white transition-colors hover:opacity-90 disabled:opacity-50"
            style={{ backgroundColor: action.destructive ? 'var(--martis-danger-hover)' : 'var(--martis-accent)' }}
          >
            <LightningIcon size={14} />
            {executeMutation.isPending
              ? t('please_wait')
              : (action.confirmButtonText ?? t('run_action'))}
          </button>
        </div>
      </div>
    </div>
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
}: {
  title: string
  onConfirm: () => Promise<void>
  onCancel: () => void
  loading: boolean
}) {
  const { t: tAct } = useTranslation('actions')
  const { t: tMsg } = useTranslation('messages')

  useModalHistoryLock(true)

  return createPortal((
    <div
      style={{ position: 'fixed', inset: 0, zIndex: 9990 }}
      className="flex items-center justify-center"
    >
      <div
        className="absolute inset-0"
        style={{ backgroundColor: 'rgba(0,0,0,0.4)' }}
        onClick={onCancel}
      />

      <div
        role="dialog"
        className="relative w-full max-w-md rounded-xl shadow-xl"
        style={{
          backgroundColor: 'var(--martis-card)',
          border: '1px solid var(--martis-border)',
        }}
      >
        {/* Header */}
        <div
          className="flex items-center justify-between border-b px-6 py-4"
          style={{ borderColor: 'var(--martis-border)' }}
        >
          <div className="flex items-center gap-3">
            <div className="flex h-10 w-10 items-center justify-center rounded-full bg-red-100 dark:bg-red-900/30">
              <LinkBreakIcon size={20} className="text-red-600 dark:text-red-400" weight="bold" />
            </div>
            <span className="text-lg font-semibold" style={{ color: 'var(--martis-text)' }}>
              {tAct('detach', 'Detach')} {title ? `"${title}"` : ''}
            </span>
          </div>
          <button
            type="button"
            onClick={onCancel}
            className="rounded-md p-1.5 transition-colors hover:bg-gray-100 dark:hover:bg-gray-800"
            style={{ color: 'var(--martis-text-muted)' }}
          >
            <XIcon size={16} />
          </button>
        </div>

        {/* Body */}
        <div className="px-6 py-4">
          <p className="text-sm" style={{ color: 'var(--martis-text-muted)' }}>
            {tMsg('detach_confirm', 'This record will be detached from the relationship. No data will be deleted. Continue?')}
          </p>
        </div>

        {/* Footer */}
        <div
          className="flex items-center justify-end gap-3 border-t px-6 py-4"
          style={{
            borderColor: 'var(--martis-border)',
            backgroundColor: 'var(--martis-surface)',
            borderRadius: '0 0 0.75rem 0.75rem',
          }}
        >
          <button type="button" onClick={onCancel} disabled={loading} className="martis-btn-secondary">
            <XIcon size={14} />
            {tAct('cancel', 'Cancel')}
          </button>
          <button
            type="button"
            disabled={loading}
            onClick={() => { void onConfirm() }}
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
    queryFn: ({ signal }) => api.get<{ data: ResourceSchema }>(`/api/resources/${relatedResource}/schema`, signal),
    enabled: !!relatedResource,
  })

  const attachableQuery = useQuery({
    queryKey: ['btm-attachable', parentResource, parentId, relationship, debouncedSearch, attachPage, attachPerPage],
    queryFn: ({ signal }) => {
      const params = new URLSearchParams({ per_page: String(attachPerPage), page: String(attachPage) })
      if (debouncedSearch) params.set('search', debouncedSearch)
      return api.get<PaginatedResponse<ResourceRecord>>(
        `/api/resources/${parentResource}/${parentId}/belongs-to-many/${relationship}/attachable?${params.toString()}`,
        signal
      )
    },
    enabled: !!parentResource && !!parentId && !!relationship,
  })

  const attachMutation = useMutation({
    mutationFn: (payload: Record<string, unknown>) =>
      api.post(
        `/api/resources/${parentResource}/${parentId}/belongs-to-many/${relationship}/attach`,
        payload
      ),
    onSuccess: () => { onSuccess() },
    onError: (e: unknown) => {
      if (e instanceof ApiError) {
        const byField = e.errorsByField()
        setFieldErrors(byField)
        // Banner is reserved for errors that aren't attached to a specific
        // field (403, 500, network, or a generic message with no per-field
        // breakdown). When we already show inline errors, repeating them
        // above the buttons just adds noise.
        setError(Object.keys(byField).length === 0 ? e.message : null)
      } else {
        setFieldErrors({})
        setError((e as { message?: string })?.message ?? 'Failed to attach.')
      }
    },
  })

  const records = attachableQuery.data?.data ?? []
  const pagination = attachableQuery.data?.meta
  const schema = schemaQuery.data?.data
  const indexFields: FieldDefinition[] = schema?.fieldsForIndex ?? []

  function handleAttach() {
    if (selected.length === 0) return
    setError(null)
    setFieldErrors({})
    if (selected.length === 1) {
      const payload: Record<string, unknown> = { related_id: selected[0].id, ...pivotValues }
      void attachMutation.mutateAsync(payload)
    } else {
      const payload: Record<string, unknown> = { related_ids: selected.map((s) => s.id), ...pivotValues }
      void attachMutation.mutateAsync(payload)
    }
  }

  const modalMaxWidth = MODAL_SIZE_MAP[modalSize] ?? MODAL_SIZE_MAP['2xl']

  return createPortal((
    <div
      style={{ position: 'fixed', inset: 0, zIndex: 9990, backgroundColor: 'rgba(0,0,0,0.5)' }}
      className="flex items-center justify-center p-4"
    >
      <div
        className="flex w-full flex-col overflow-hidden rounded-xl shadow-xl"
        style={{
          backgroundColor: 'var(--martis-card)',
          border: '1px solid var(--martis-border)',
          maxHeight: modalHeight ?? '85vh',
          maxWidth: modalMaxWidth,
        }}
      >
        {/* Header */}
        <div
          className="flex shrink-0 items-center justify-between border-b px-6 py-4"
          style={{ borderColor: 'var(--martis-border)' }}
        >
          <div className="flex items-center gap-2">
            <h3 className="text-lg font-semibold" style={{ color: 'var(--martis-text)' }}>
              {tAct('attach_related', 'Attach Record')}
            </h3>
            {selected.length > 0 && (
              <span
                className="martis-badge"
                style={{ backgroundColor: 'var(--martis-accent)', color: '#fff', borderColor: 'transparent' }}
              >
                {selected.length}
              </span>
            )}
          </div>
          <button
            type="button"
            onClick={onClose}
            className="rounded p-1"
            style={{ color: 'var(--martis-text-muted)', background: 'none', border: 'none', cursor: 'pointer' }}
          >
            <XIcon size={18} />
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
        {pivotFields.length > 0 && selected.length > 0 && (
          <div
            className="shrink-0 space-y-4 border-t px-6 py-4"
            style={{ borderColor: 'var(--martis-border)' }}
          >
            <p className="text-xs font-medium uppercase tracking-wider" style={{ color: 'var(--martis-text-muted)' }}>
              {tAct('pivot_fields', 'Pivot Fields')}
            </p>
            {pivotFields.map((pf) => {
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
          <div className="shrink-0 mx-6 mb-3 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-300">
            {error}
          </div>
        )}

        {/* Footer — always pinned at bottom of modal */}
        <div
          className="flex shrink-0 items-center justify-end gap-3 border-t px-6 py-4"
          style={{ borderColor: 'var(--martis-border)' }}
        >
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
// Forms — no-op (BelongsToMany only on detail page)
// -------------------------------------------------------------------------

export function BelongsToManyFieldInput({ field }: FieldInputProps) {
  return <BelongsToManyDetailPanel field={field} />
}

// -------------------------------------------------------------------------
// Edit pivot modal — updates the pivot row for an already-attached record.
// Shared by BelongsToMany and MorphToMany via direct reuse (the endpoint
// shape is identical: PUT {parent}/{id}/<rel-type>/{rel}/{relatedId}/pivot).
// -------------------------------------------------------------------------

export function EditPivotModal({
  title,
  endpoint,
  pivotFields,
  initialValues,
  onSuccess,
  onCancel,
}: {
  title: string
  endpoint: string
  pivotFields: FieldDefinition[]
  initialValues: Record<string, unknown>
  onSuccess: () => void
  onCancel: () => void
}) {
  const { t: tAct } = useTranslation('actions')

  useModalHistoryLock(true)

  const [values, setValues] = useState<Record<string, unknown>>(() => {
    const seeded: Record<string, unknown> = {}
    for (const pf of pivotFields) {
      seeded[pf.attribute] = initialValues[pf.attribute] ?? null
    }
    return seeded
  })
  const [error, setError] = useState<string | null>(null)
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({})

  const updateMutation = useMutation({
    mutationFn: (payload: Record<string, unknown>) => api.put(endpoint, payload),
    onSuccess: () => { onSuccess() },
    onError: (e: unknown) => {
      if (e instanceof ApiError) {
        const byField = e.errorsByField()
        setFieldErrors(byField)
        setError(Object.keys(byField).length === 0 ? e.message : null)
      } else {
        setFieldErrors({})
        setError((e as { message?: string })?.message ?? 'Failed to update.')
      }
    },
  })

  function handleSave() {
    setError(null)
    setFieldErrors({})
    void updateMutation.mutateAsync(values)
  }

  return createPortal((
    <div
      style={{ position: 'fixed', inset: 0, zIndex: 9990 }}
      className="flex items-center justify-center"
    >
      <div
        className="absolute inset-0"
        style={{ backgroundColor: 'rgba(0,0,0,0.4)' }}
        onClick={onCancel}
      />

      <div
        role="dialog"
        className="relative w-full max-w-md rounded-xl shadow-xl"
        style={{
          backgroundColor: 'var(--martis-card)',
          border: '1px solid var(--martis-border)',
        }}
      >
        <div
          className="flex items-center justify-between border-b px-6 py-4"
          style={{ borderColor: 'var(--martis-border)' }}
        >
          <div className="flex items-center gap-3">
            <div className="flex h-10 w-10 items-center justify-center rounded-full bg-indigo-100 dark:bg-indigo-900/30">
              <PencilSimpleIcon size={20} className="text-indigo-600 dark:text-indigo-400" weight="bold" />
            </div>
            <span className="text-lg font-semibold" style={{ color: 'var(--martis-text)' }}>
              {tAct('edit', 'Edit')} {title ? `"${title}"` : ''}
            </span>
          </div>
          <button
            type="button"
            onClick={onCancel}
            className="rounded-md p-1.5 transition-colors hover:bg-gray-100 dark:hover:bg-gray-800"
            style={{ color: 'var(--martis-text-muted)' }}
          >
            <XIcon size={16} />
          </button>
        </div>

        <div className="px-6 py-4 space-y-4">
          {pivotFields.map((pf) => {
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
                  value={values[pf.attribute] ?? null}
                  onChange={(v) => setValues((prev) => ({ ...prev, [pf.attribute]: v }))}
                />
                {fieldError && (
                  <p className="mt-1 text-xs" style={{ color: 'var(--martis-danger)' }}>{fieldError}</p>
                )}
              </div>
            )
          })}
          {error && (
            <div className="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-300">
              {error}
            </div>
          )}
        </div>

        <div
          className="flex items-center justify-end gap-3 border-t px-6 py-4"
          style={{
            borderColor: 'var(--martis-border)',
            backgroundColor: 'var(--martis-surface)',
            borderRadius: '0 0 0.75rem 0.75rem',
          }}
        >
          <button type="button" onClick={onCancel} disabled={updateMutation.isPending} className="martis-btn-secondary">
            <XIcon size={14} />
            {tAct('cancel', 'Cancel')}
          </button>
          <button
            type="button"
            disabled={updateMutation.isPending}
            onClick={handleSave}
            className="martis-btn-primary"
          >
            <FloppyDiskIcon size={14} />
            {updateMutation.isPending ? tAct('please_wait', 'Please wait…') : tAct('save', 'Save')}
          </button>
        </div>
      </div>
    </div>
  ), document.body)
}
