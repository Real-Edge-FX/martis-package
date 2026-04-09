import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { api } from '@/lib/api'
import type { PaginatedResponse, ResourceRecord, ResourceSchema, FieldDefinition } from '@/types'
import type { FieldDisplayProps, FieldInputProps } from './types'
import { FieldDisplay, FieldInput } from '@/components/fields'
import { useTranslation } from 'react-i18next'
import {
  Plus,
  LinkSimple,
  LinkBreak,
  MagnifyingGlass,
  CaretUp,
  CaretDown,
  CaretUpDown,
  X,
  Check,
} from '@phosphor-icons/react'
import { DataTable, type DataTableSortEvent } from 'primereact/datatable'
import { Column } from 'primereact/column'

// -------------------------------------------------------------------------
// BelongsToMany index display — count badge
// -------------------------------------------------------------------------

export function BelongsToManyFieldDisplay({ field, value }: FieldDisplayProps) {
  if (typeof value === 'number') {
    return <BelongsToManyCountBadge count={value} />
  }

  // Detail page — render the full panel
  return <BelongsToManyDetailPanel field={field} />
}

function BelongsToManyCountBadge({ count }: { count: number }) {
  return (
    <span
      className="inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-medium"
      style={{
        backgroundColor: 'var(--martis-surface)',
        color: 'var(--martis-text)',
        border: '1px solid var(--martis-border)',
      }}
    >
      <LinkSimple size={11} />
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
}

interface AttachableRecord {
  id: string | number
  _title?: string
}

function BelongsToManyDetailPanel({ field }: { field: FieldDisplayProps['field'] }) {
  const { t: tAct } = useTranslation('actions')
  const { t: tMsg } = useTranslation('messages')
  const qc = useQueryClient()

  const meta = field.belongsToManyMeta as BtmMeta | undefined
  const relationship = field.relationship as string
  const relatedResource = field.relatedResource as string
  const collapsable = field.collapsable as boolean
  const collapsedByDefault = field.collapsedByDefault as boolean
  const pivotFields = (field.pivotFields as FieldDefinition[] | undefined) ?? []
  const searchable = field.searchable as boolean

  const pathParts = window.location.pathname.split('/')
  const resourcesIdx = pathParts.indexOf('resources')
  const parentResource = resourcesIdx >= 0 ? pathParts[resourcesIdx + 1] : ''
  const parentId = resourcesIdx >= 0 ? pathParts[resourcesIdx + 2] : ''

  const [search, setSearch] = useState('')
  const [page, setPage] = useState(1)
  const [perPage, setPerPage] = useState(meta?.perPage ?? 10)
  const [sort, setSort] = useState<string | null>(null)
  const [direction, setDirection] = useState<'asc' | 'desc'>('asc')
  const [collapsed, setCollapsed] = useState(collapsedByDefault)
  const [showAttachModal, setShowAttachModal] = useState(false)
  const [detachTarget, setDetachTarget] = useState<{ id: string | number; title?: string } | null>(null)

  // Schema for column headers
  const schemaQuery = useQuery({
    queryKey: ['schema', relatedResource],
    queryFn: () => api.get<{ data: ResourceSchema }>(`/api/resources/${relatedResource}/schema`),
    enabled: !!relatedResource,
  })

  // Attached records
  const recordsQuery = useQuery({
    queryKey: ['belongs-to-many', parentResource, parentId, relationship, { search, page, perPage, sort, direction }],
    queryFn: () => {
      const params = new URLSearchParams()
      if (search) params.set('search', search)
      params.set('page', String(page))
      params.set('per_page', String(perPage))
      if (sort) { params.set('sort', sort); params.set('direction', direction) }
      return api.get<PaginatedResponse<ResourceRecord>>(
        `/api/resources/${parentResource}/${parentId}/belongs-to-many/${relationship}?${params.toString()}`
      )
    },
    enabled: !!parentResource && !!parentId && !!relationship && !collapsed,
  })

  const detachMutation = useMutation({
    mutationFn: (relatedId: string | number) =>
      api.delete(
        `/api/resources/${parentResource}/${parentId}/belongs-to-many/${relationship}/${relatedId}/detach`
      ),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ['belongs-to-many', parentResource, parentId, relationship] })
      setDetachTarget(null)
    },
  })

  const schema = schemaQuery.data?.data
  const records = recordsQuery.data?.data ?? []
  const pagination = recordsQuery.data?.meta
  const totalCount = pagination?.total ?? records.length

  const indexFields: FieldDefinition[] = schema?.fieldsForIndex ?? []

  function handleSort(attribute: string) {
    if (sort === attribute) {
      setDirection((d) => (d === 'asc' ? 'desc' : 'asc'))
    } else {
      setSort(attribute)
      setDirection('asc')
    }
    setPage(1)
  }

  function SortIcon({ active, dir }: { active: boolean; dir: 'asc' | 'desc' }) {
    if (!active) return <CaretUpDown size={14} className="text-gray-400" />
    return dir === 'asc'
      ? <CaretUp size={14} className="text-indigo-600" />
      : <CaretDown size={14} className="text-indigo-600" />
  }

  return (
    <div className="mt-6 w-full">
      {/* Header — title, collapse toggle, icon, count */}
      <h3 className="mb-3 flex items-center gap-2 text-lg font-semibold" style={{ color: 'var(--martis-text)' }}>
        {collapsable && (
          <button
            type="button"
            onClick={() => setCollapsed((c) => !c)}
            className="rounded p-0.5 transition-colors"
            style={{ color: 'var(--martis-text-muted)', background: 'none', border: 'none', cursor: 'pointer' }}
          >
            {collapsed ? <CaretDown size={16} /> : <CaretUp size={16} />}
          </button>
        )}
        <LinkSimple size={18} style={{ color: 'var(--martis-accent)' }} />
        <span>{field.label}</span>
        <span
          className="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium"
          style={{
            backgroundColor: 'var(--martis-surface)',
            color: 'var(--martis-text-muted)',
            border: '1px solid var(--martis-border)',
          }}
        >
          {totalCount}
        </span>
      </h3>

      {/* Collapsible content — card container with toolbar + table + pagination */}
      {!collapsed && (
        <div
          className="overflow-hidden rounded-xl border"
          style={{
            borderColor: 'var(--martis-border)',
            backgroundColor: 'var(--martis-card)',
          }}
        >
          {/* Toolbar — search + attach button, inside the card */}
          {(searchable || meta?.canAttach) && (
            <div
              className="flex flex-wrap items-center justify-between gap-2 px-4 py-3"
              style={{ borderBottom: '1px solid var(--martis-border)' }}
            >
              {searchable ? (
                <div className="relative" style={{ minWidth: '150px', maxWidth: '300px', flex: '1 1 auto' }}>
                  <MagnifyingGlass
                    size={14}
                    className="absolute left-2.5 top-1/2 -translate-y-1/2"
                    style={{ color: 'var(--martis-text-muted)' }}
                  />
                  <input
                    type="text"
                    value={search}
                    onChange={(e) => { setSearch(e.target.value); setPage(1) }}
                    placeholder={tMsg('search', 'Search…')}
                    className="btm-search-input w-full rounded-md border py-1.5 pl-8 pr-3 text-sm"
                    style={{
                      borderColor: 'var(--martis-border)',
                      backgroundColor: 'var(--martis-input-bg)',
                      color: 'var(--martis-text)',
                    }}
                  />
                </div>
              ) : <div />}
              {meta?.canAttach && (
                <button
                  type="button"
                  onClick={() => setShowAttachModal(true)}
                  className="inline-flex shrink-0 items-center gap-1.5 rounded-md px-3 py-1.5 text-sm font-medium text-white"
                  style={{ backgroundColor: 'var(--martis-accent)' }}
                >
                  <Plus size={14} weight="bold" />
                  {tAct('attach', 'Attach')}
                </button>
              )}
            </div>
          )}

          {/* DataTable */}
          <DataTable
            value={records}
            loading={recordsQuery.isLoading}
            dataKey="id"
            removableSort
            sortField={sort ?? undefined}
            sortOrder={direction === 'asc' ? 1 : -1}
            onSort={(e: DataTableSortEvent) => {
              if (e.sortField) handleSort(String(e.sortField))
            }}
            emptyMessage={
              <div className="py-8 text-center text-sm" style={{ color: 'var(--martis-text-muted)' }}>
                {tMsg('no_records_available', 'No records available.')}
              </div>
            }
            className="w-full martis-datatable martis-datatable-striped"
            tableClassName="min-w-full"
          >
            {indexFields.map((f) => (
              <Column
                key={f.attribute}
                field={f.attribute}
                header={
                  f.sortable ? (
                    <button
                      type="button"
                      className="flex items-center gap-1 font-medium uppercase tracking-wider text-xs text-gray-500 hover:text-gray-900 dark:hover:text-white"
                      onClick={() => handleSort(f.attribute)}
                    >
                      {f.label}
                      <SortIcon active={sort === f.attribute} dir={direction} />
                    </button>
                  ) : (
                    <span className="text-xs font-medium uppercase tracking-wider text-gray-500">
                      {f.label}
                    </span>
                  )
                }
                body={(row: ResourceRecord) => (
                  f.attribute === 'id' ? (
                    <Link
                      to={`/resources/${relatedResource}/${row.id}`}
                      className="font-medium no-underline"
                      style={{ color: 'var(--martis-primary)' }}
                    >
                      <FieldDisplay field={f} value={row[f.attribute]} resourceKey={relatedResource} />
                    </Link>
                  ) : (
                    <FieldDisplay field={f} value={row[f.attribute]} resourceKey={relatedResource} />
                  )
                )}
                sortable={false}
              />
            ))}

            {/* Pivot field columns */}
            {pivotFields.map((pf) => (
              <Column
                key={`pivot_${pf.attribute}`}
                field={`_pivot.${pf.attribute}`}
                header={
                  <span className="text-xs font-medium uppercase tracking-wider text-gray-500">
                    {pf.label}
                  </span>
                }
                body={(row: ResourceRecord) => {
                  const pivot = row._pivot as Record<string, unknown> | undefined
                  const val = pivot?.[pf.attribute]
                  return (
                    <span className="text-sm" style={{ color: 'var(--martis-text)' }}>
                      {val != null ? String(val) : '—'}
                    </span>
                  )
                }}
                sortable={false}
              />
            ))}

            {/* Actions column */}
            {meta?.canDetach && (
              <Column
                header={
                  <span className="text-xs font-medium uppercase tracking-wider text-gray-500">
                    {tAct('actions', 'Actions')}
                  </span>
                }
                body={(row: ResourceRecord) => (
                  <div className="flex items-center justify-end gap-1">
                    {meta?.canDetach && (
                      <button
                        type="button"
                        onClick={() => setDetachTarget({ id: row.id as string | number, title: row._title as string })}
                        className="inline-flex items-center gap-1 rounded px-2 py-1 text-xs transition-colors"
                        style={{ color: 'var(--martis-text-muted)', background: 'none', border: '1px solid var(--martis-border)', cursor: 'pointer' }}
                        title={tAct('detach', 'Detach')}
                        onMouseEnter={(e) => {
                          e.currentTarget.style.color = '#ef4444'
                          e.currentTarget.style.borderColor = '#ef4444'
                        }}
                        onMouseLeave={(e) => {
                          e.currentTarget.style.color = 'var(--martis-text-muted)'
                          e.currentTarget.style.borderColor = 'var(--martis-border)'
                        }}
                      >
                        <LinkBreak size={14} />
                        {tAct('detach', 'Detach')}
                      </button>
                    )}
                  </div>
                )}
                style={{ width: '8rem', textAlign: 'right' }}
              />
            )}
          </DataTable>

          {/* Pagination */}
          {pagination && pagination.last_page > 1 && (
            <div
              className="flex items-center justify-between px-4 py-3"
              style={{
                borderTop: '1px solid var(--martis-border)',
                backgroundColor: 'var(--martis-surface)',
              }}
            >
              <div className="flex items-center gap-2 text-xs" style={{ color: 'var(--martis-text-muted)' }}>
                <span>{pagination.from}–{pagination.to} / {pagination.total}</span>
                {meta?.perPageOptions && (
                  <select
                    value={perPage}
                    onChange={(e) => { setPerPage(Number(e.target.value)); setPage(1) }}
                    className="rounded border px-1.5 py-0.5 text-xs"
                    style={{
                      borderColor: 'var(--martis-border)',
                      backgroundColor: 'var(--martis-input-bg)',
                      color: 'var(--martis-text)',
                    }}
                  >
                    {meta.perPageOptions.map((opt) => (
                      <option key={opt} value={opt}>{opt}</option>
                    ))}
                  </select>
                )}
              </div>
              <div className="flex items-center gap-1">
                <button
                  type="button"
                  disabled={page <= 1}
                  onClick={() => setPage((p) => Math.max(1, p - 1))}
                  className="rounded border px-2 py-1 text-xs font-medium disabled:opacity-40"
                  style={{
                    borderColor: 'var(--martis-border)',
                    backgroundColor: 'var(--martis-input-bg)',
                    color: 'var(--martis-text)',
                  }}
                >
                  ←
                </button>
                <span className="px-2 text-xs" style={{ color: 'var(--martis-text-muted)' }}>
                  {page} / {pagination.last_page}
                </span>
                <button
                  type="button"
                  disabled={page >= pagination.last_page}
                  onClick={() => setPage((p) => p + 1)}
                  className="rounded border px-2 py-1 text-xs font-medium disabled:opacity-40"
                  style={{
                    borderColor: 'var(--martis-border)',
                    backgroundColor: 'var(--martis-input-bg)',
                    color: 'var(--martis-text)',
                  }}
                >
                  →
                </button>
              </div>
            </div>
          )}
        </div>
      )}

      {/* Detach confirmation */}
      {detachTarget && (
        <DetachConfirmModal
          title={detachTarget.title ?? String(detachTarget.id)}
          onConfirm={async () => { await detachMutation.mutateAsync(detachTarget.id) }}
          onCancel={() => setDetachTarget(null)}
          loading={detachMutation.isPending}
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
          onSuccess={() => {
            setShowAttachModal(false)
            void qc.invalidateQueries({ queryKey: ['belongs-to-many', parentResource, parentId, relationship] })
          }}
          onClose={() => setShowAttachModal(false)}
        />
      )}

      <style>{`
        .btm-search-input::placeholder { color: var(--martis-text-muted); opacity: 0.7; }
      `}</style>
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

  return (
    <div
      className="fixed inset-0 z-50 flex items-center justify-center p-4"
      style={{ backgroundColor: 'rgba(0,0,0,0.5)' }}
    >
      <div
        className="w-full max-w-md overflow-hidden rounded-xl p-6 shadow-xl"
        style={{ backgroundColor: 'var(--martis-card)', border: '1px solid var(--martis-border)' }}
      >
        <h3 className="mb-2 text-lg font-semibold" style={{ color: 'var(--martis-text)' }}>
          {tAct('detach', 'Detach')}
        </h3>
        <p className="mb-6 text-sm" style={{ color: 'var(--martis-text-muted)' }}>
          {tMsg('detach_confirm', 'This record will be detached from the relationship. No data will be deleted. Continue?')}
        </p>
        <p className="mb-6 text-sm font-medium" style={{ color: 'var(--martis-text)' }}>"{title}"</p>
        <div className="flex justify-end gap-3">
          <button
            type="button"
            onClick={onCancel}
            className="rounded-lg px-4 py-2 text-sm font-medium"
            style={{
              backgroundColor: 'var(--martis-surface)',
              color: 'var(--martis-text)',
              border: '1px solid var(--martis-border)',
            }}
          >
            {tAct('cancel', 'Cancel')}
          </button>
          <button
            type="button"
            disabled={loading}
            onClick={() => { void onConfirm() }}
            className="rounded-lg px-4 py-2 text-sm font-medium text-white disabled:opacity-60"
            style={{ backgroundColor: '#ef4444' }}
          >
            {loading ? tAct('please_wait', 'Please wait…') : tAct('detach', 'Detach')}
          </button>
        </div>
      </div>
    </div>
  )
}

// -------------------------------------------------------------------------
// Attach modal — search + select + optional pivot fields
// -------------------------------------------------------------------------

function AttachModal({
  parentResource,
  parentId,
  relationship,
  relatedResource: _relatedResource,
  pivotFields,
  onSuccess,
  onClose,
}: {
  parentResource: string
  parentId: string
  relationship: string
  relatedResource: string
  pivotFields: FieldDefinition[]
  onSuccess: () => void
  onClose: () => void
}) {
  const { t: tAct } = useTranslation('actions')
  const { t: tMsg } = useTranslation('messages')

  const [search, setSearch] = useState('')
  const [selected, setSelected] = useState<AttachableRecord | null>(null)
  const [pivotValues, setPivotValues] = useState<Record<string, unknown>>({})
  const [error, setError] = useState<string | null>(null)

  const attachableQuery = useQuery({
    queryKey: ['btm-attachable', parentResource, parentId, relationship, search],
    queryFn: () => {
      const params = new URLSearchParams({ per_page: '20' })
      if (search) params.set('search', search)
      return api.get<PaginatedResponse<AttachableRecord>>(
        `/api/resources/${parentResource}/${parentId}/belongs-to-many/${relationship}/attachable?${params.toString()}`
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
      const msg = (e as { message?: string })?.message ?? 'Failed to attach.'
      setError(msg)
    },
  })

  const records = attachableQuery.data?.data ?? []

  function handleAttach() {
    if (!selected) return
    setError(null)
    const payload: Record<string, unknown> = { related_id: selected.id, ...pivotValues }
    void attachMutation.mutateAsync(payload)
  }

  return (
    <div
      className="fixed inset-0 z-50 flex items-center justify-center p-4"
      style={{ backgroundColor: 'rgba(0,0,0,0.5)' }}
    >
      <div
        className="flex w-full max-w-lg flex-col overflow-hidden rounded-xl shadow-xl"
        style={{
          backgroundColor: 'var(--martis-card)',
          border: '1px solid var(--martis-border)',
          maxHeight: '80vh',
        }}
      >
        {/* Header */}
        <div
          className="flex shrink-0 items-center justify-between border-b px-6 py-4"
          style={{ borderColor: 'var(--martis-border)' }}
        >
          <h3 className="text-lg font-semibold" style={{ color: 'var(--martis-text)' }}>
            {tAct('attach_related', 'Attach Record')}
          </h3>
          <button
            type="button"
            onClick={onClose}
            className="rounded p-1"
            style={{ color: 'var(--martis-text-muted)', background: 'none', border: 'none', cursor: 'pointer' }}
          >
            <X size={18} />
          </button>
        </div>

        {/* Search */}
        <div className="shrink-0 border-b px-6 py-3" style={{ borderColor: 'var(--martis-border)' }}>
          <div className="relative">
            <MagnifyingGlass
              size={14}
              className="absolute left-3 top-1/2 -translate-y-1/2"
              style={{ color: 'var(--martis-text-muted)' }}
            />
            <input
              type="text"
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              placeholder={tMsg('search', 'Search…')}
              className="w-full rounded-lg border py-2 pl-9 pr-3 text-sm btm-modal-search"
              style={{
                borderColor: 'var(--martis-border)',
                backgroundColor: 'var(--martis-input-bg)',
                color: 'var(--martis-text)',
              }}
              autoFocus
            />
          </div>
        </div>

        {/* Record list — scrollable, takes remaining space */}
        <div className="min-h-0 flex-1 overflow-y-auto px-6 py-3">
          {attachableQuery.isLoading ? (
            <p className="py-4 text-center text-sm" style={{ color: 'var(--martis-text-muted)' }}>
              {tMsg('loading', 'Loading…')}
            </p>
          ) : records.length === 0 ? (
            <p className="py-4 text-center text-sm" style={{ color: 'var(--martis-text-muted)' }}>
              {tMsg('no_records_available', 'No records available.')}
            </p>
          ) : (
            <div className="space-y-1">
              {records.map((rec) => (
                <button
                  key={rec.id}
                  type="button"
                  onClick={() => setSelected(rec)}
                  className="flex w-full items-center justify-between rounded-lg px-3 py-2 text-left text-sm transition-colors"
                  style={{
                    backgroundColor: selected?.id === rec.id ? 'var(--martis-accent)' : 'transparent',
                    color: selected?.id === rec.id ? '#fff' : 'var(--martis-text)',
                    border: '1px solid',
                    borderColor: selected?.id === rec.id ? 'var(--martis-accent)' : 'transparent',
                    cursor: 'pointer',
                  }}
                >
                  <span>{rec._title ?? String(rec.id)}</span>
                  {selected?.id === rec.id && <Check size={14} />}
                </button>
              ))}
            </div>
          )}
        </div>

        {/* Pivot fields (if any) */}
        {pivotFields.length > 0 && selected && (
          <div
            className="shrink-0 space-y-4 border-t px-6 py-4"
            style={{ borderColor: 'var(--martis-border)' }}
          >
            <p className="text-xs font-medium uppercase tracking-wider" style={{ color: 'var(--martis-text-muted)' }}>
              Pivot Fields
            </p>
            {pivotFields.map((pf) => (
              <div key={pf.attribute}>
                <label className="mb-1 block text-sm font-medium" style={{ color: 'var(--martis-text)' }}>
                  {pf.label}
                </label>
                <FieldInput
                  field={pf}
                  value={pivotValues[pf.attribute] ?? null}
                  onChange={(v) => setPivotValues((prev) => ({ ...prev, [pf.attribute]: v }))}
                />
              </div>
            ))}
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
          <button
            type="button"
            onClick={onClose}
            className="rounded-lg px-4 py-2 text-sm font-medium"
            style={{
              backgroundColor: 'var(--martis-surface)',
              color: 'var(--martis-text)',
              border: '1px solid var(--martis-border)',
            }}
          >
            {tAct('cancel', 'Cancel')}
          </button>
          <button
            type="button"
            disabled={!selected || attachMutation.isPending}
            onClick={handleAttach}
            className="inline-flex items-center gap-1.5 rounded-lg px-4 py-2 text-sm font-medium text-white disabled:opacity-50"
            style={{ backgroundColor: 'var(--martis-accent)' }}
          >
            <LinkSimple size={14} />
            {attachMutation.isPending ? tAct('please_wait', 'Please wait…') : tAct('attach', 'Attach')}
          </button>
        </div>
      </div>

      <style>{`
        .btm-modal-search::placeholder { color: var(--martis-text-muted); opacity: 0.7; }
      `}</style>
    </div>
  )
}

// -------------------------------------------------------------------------
// Forms — no-op (BelongsToMany only on detail page)
// -------------------------------------------------------------------------

export function BelongsToManyFieldInput(_props: FieldInputProps) {
  return null
}
