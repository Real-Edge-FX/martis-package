import { useState, type ReactNode } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Link, useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { DataTable, type DataTableSortEvent, type DataTableSelectionMultipleChangeEvent } from 'primereact/datatable'
import { Column } from 'primereact/column'
import {
  PlusIcon, EyeIcon, PencilSimpleIcon, TrashIcon, MagnifyingGlassIcon, XIcon,
  CaretUpIcon, CaretDownIcon, CaretUpDownIcon, CaretRightIcon,
  ArrowCounterClockwiseIcon, SkullIcon,
} from '@phosphor-icons/react'

import { api } from '@/lib/api'
import type { PaginatedResponse, ResourceRecord, ResourceSchema, FieldDefinition } from '@/types'
import { FieldDisplay } from '@/components/fields/FieldRenderer'
import { DeleteModal } from '@/components/DeleteModal'
import { ResourceIcon } from '@/components/ResourceIcon'
import { Pagination } from '@/components/Pagination'

/**
 * Shared toolbar/table/pagination shell for *-Many relationship fields.
 *
 * Owns ephemeral UI state (search, page, perPage, sort, delete target) and
 * renders the relationship panel. Callers supply endpoint builders and meta;
 * the shell is agnostic of HasMany vs MorphMany semantics.
 *
 * Authorization gates (`canCreate`/`canUpdate`/`canDelete`) AND programmer
 * hide flags (`hideXxx`) compose: an action appears only when authorized AND
 * not explicitly hidden. Unauthorized actions never render.
 */
export interface RelationshipTableShellProps {
  title: string
  relatedResource: string
  relatedIcon?: string | null
  showRelationIcon?: boolean
  showRelationCount?: boolean
  collapsable?: boolean
  collapsedByDefault?: boolean

  queryKey: unknown[]
  fetchUrl: (params: URLSearchParams) => string
  /** Optional — when omitted the trash icon never renders (useful for pivot
   *  relations where "delete" is expressed as "detach" via `rowActionsExtras`). */
  deleteUrl?: (relatedId: string | number) => string
  createUrl?: string | null
  editUrl?: (id: string | number) => string
  viewUrl?: (id: string | number) => string

  /** Extra columns rendered after the related resource's indexFields.
   *  Used by BelongsToMany/MorphToMany to surface pivot attributes. */
  pivotFields?: FieldDefinition[]

  /** Render a multi-select checkbox column. Consumers must pass the
   *  selected rows back as a controlled prop + handle change. */
  selectable?: boolean
  selectedRows?: ResourceRecord[]
  onSelectionChange?: (rows: ResourceRecord[]) => void

  perPage: number
  perPageOptions: number[]
  searchable: boolean
  canCreate: boolean
  canUpdate: boolean
  canDelete: boolean
  hideSearch?: boolean
  hideCreateButton?: boolean
  hidePerPageSelector?: boolean
  hideSoftDeleteToggle?: boolean
  hideViewAction?: boolean
  hideEditAction?: boolean
  hideDeleteAction?: boolean
  hideRestoreAction?: boolean
  hideForceDeleteAction?: boolean

  /** Initial soft-delete filter. Reads from config.default_trashed_filter when omitted. */
  defaultTrashed?: 'active' | 'with' | 'only'

  /** Render extra controls in the primary toolbar (after Criar). Receives
   *  the currently-selected rows so consumers can render pivot action
   *  dropdowns with a "2 selected" badge, etc. */
  toolbarExtras?: ReactNode | ((ctx: { selectedRows: ResourceRecord[] }) => ReactNode)
  rowActionsExtras?: (row: ResourceRecord) => ReactNode
}

export function RelationshipTableShell(props: RelationshipTableShellProps) {
  const {
    title, relatedResource, relatedIcon,
    showRelationIcon = true, showRelationCount = true,
    collapsable = false, collapsedByDefault = false,
    queryKey, fetchUrl, deleteUrl, createUrl, editUrl, viewUrl,
    pivotFields,
    selectable, selectedRows, onSelectionChange,
    perPage: perPageDefault, perPageOptions, searchable,
    canCreate, canUpdate, canDelete,
    hideSearch, hideCreateButton, hidePerPageSelector, hideSoftDeleteToggle,
    hideViewAction, hideEditAction, hideDeleteAction,
    hideRestoreAction, hideForceDeleteAction,
    defaultTrashed,
    toolbarExtras, rowActionsExtras,
  } = props

  const { t: tAct } = useTranslation('actions')
  const { t: tMsg } = useTranslation('messages')
  const { t: tRes } = useTranslation('resources')
  const qc = useQueryClient()
  const navigate = useNavigate()

  const [search, setSearch] = useState('')
  const [page, setPage] = useState(1)
  const [perPage, setPerPage] = useState(perPageDefault)
  const [sort, setSort] = useState<string | null>(null)
  const [direction, setDirection] = useState<'asc' | 'desc'>('asc')
  const [deleteTarget, setDeleteTarget] = useState<{ id: string | number } | null>(null)
  const [forceDeleteTarget, setForceDeleteTarget] = useState<{ id: string | number } | null>(null)
  const [restoreTarget, setRestoreTarget] = useState<{ id: string | number } | null>(null)
  const [isCollapsed, setIsCollapsed] = useState(collapsedByDefault)
  const [trashed, setTrashed] = useState<'active' | 'with' | 'only'>(defaultTrashed ?? 'active')

  const schemaQuery = useQuery({
    queryKey: ['schema', relatedResource],
    queryFn: () => api.get<{ data: ResourceSchema }>(`/api/resources/${relatedResource}/schema`),
    enabled: !!relatedResource,
  })

  const recordsQuery = useQuery({
    queryKey: [...queryKey, { search, page, perPage, sort, direction, trashed }],
    queryFn: () => {
      const params = new URLSearchParams()
      if (search) params.set('search', search)
      params.set('page', String(page))
      params.set('per_page', String(perPage))
      if (sort) {
        params.set('sort', sort)
        params.set('direction', direction)
      }
      if (trashed !== 'active') params.set('trashed', trashed)
      return api.get<PaginatedResponse<ResourceRecord>>(fetchUrl(params))
    },
  })

  const deleteMutation = useMutation({
    mutationFn: (relatedId: string | number) => {
      if (!deleteUrl) return Promise.resolve(undefined)
      return api.delete(deleteUrl(relatedId))
    },
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey })
      setDeleteTarget(null)
    },
  })

  const restoreMutation = useMutation({
    mutationFn: (relatedId: string | number) =>
      api.put(`/api/resources/${relatedResource}/${relatedId}/restore`),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey })
      setRestoreTarget(null)
    },
  })

  const forceDeleteMutation = useMutation({
    mutationFn: (relatedId: string | number) =>
      api.delete(`/api/resources/${relatedResource}/${relatedId}/force`),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey })
      setForceDeleteTarget(null)
    },
  })

  const schema = schemaQuery.data?.data
  const records = recordsQuery.data?.data ?? []
  const pagination = recordsQuery.data?.meta
  const totalCount = pagination?.total ?? records.length

  const indexFields: FieldDefinition[] = schema?.fieldsForIndex ?? []

  const softDeletes = !!(schema as unknown as { softDeletes?: boolean })?.softDeletes

  const showView = !hideViewAction
  const showEdit = canUpdate && !hideEditAction && !!editUrl
  const showDelete = canDelete && !hideDeleteAction && !!deleteUrl
  const showRestore = softDeletes && !hideRestoreAction
  const showForceDelete = softDeletes && !hideForceDeleteAction
  const hasActions = showView || showEdit || showDelete || showRestore || showForceDelete || !!rowActionsExtras

  const showSearch = searchable && !hideSearch
  const showCreate = canCreate && !hideCreateButton && !!createUrl
  const showPerPage = !hidePerPageSelector && perPageOptions?.length > 0
  const showSoftDeleteToggle = softDeletes && !hideSoftDeleteToggle

  const searchPlaceholder = (schema as unknown as { searchPlaceholder?: string })?.searchPlaceholder
    ?? tMsg('search', 'Search...')
  const iconFromSchema = (schema as unknown as { icon?: string })?.icon
  const displayIcon = relatedIcon ?? iconFromSchema

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
    if (!active) return <CaretUpDownIcon size={14} className="text-gray-400" />
    return dir === 'asc'
      ? <CaretUpIcon size={14} className="text-indigo-600" />
      : <CaretDownIcon size={14} className="text-indigo-600" />
  }

  const showMeta = !isCollapsed && (showPerPage || showSoftDeleteToggle)

  return (
    <div className="mt-6 space-y-3">
      {/* Toolbar — container-query driven. Narrow (<48rem container):
       *   row 1 = heading (left) + search + create (right);
       *   row 2 = per-page (left) + trashed (right).
       * Wide (>=48rem): everything collapses onto a single row with
       *   heading on the left and [search + create + per-page + trashed]
       *   on the right. See `.martis-relation-toolbar` in martis.css. */}
      <div className="martis-relation-toolbar">
        <h3
          className={`martis-relation-heading flex items-center gap-2 text-lg font-semibold ${collapsable ? 'cursor-pointer select-none' : ''}`}
          style={{ color: 'var(--martis-text)' }}
          onClick={collapsable ? () => setIsCollapsed((v) => !v) : undefined}
        >
          {showRelationIcon && displayIcon && (
            <ResourceIcon iconName={displayIcon} size={20} />
          )}
          {collapsable && (
            <CaretRightIcon
              size={14}
              weight="bold"
              style={{
                transform: isCollapsed ? 'rotate(0deg)' : 'rotate(90deg)',
                transition: 'transform 0.15s',
                color: 'var(--martis-text-muted)',
              }}
            />
          )}
          <span>{title}</span>
          {showRelationCount && (
            <span
              className="martis-badge"
              style={{
                backgroundColor: 'var(--martis-surface)',
                color: 'var(--martis-text-muted)',
                borderColor: 'var(--martis-border)',
              }}
            >
              {totalCount}
            </span>
          )}
        </h3>
        {!isCollapsed && (
          <div className="martis-relation-primary">
            {showSearch && (
              <div className="relative flex-shrink-0" style={{ width: '16rem' }}>
                <span className="absolute inset-y-0 left-3 flex items-center pointer-events-none">
                  <MagnifyingGlassIcon size={14} style={{ color: 'var(--martis-text-muted)' }} />
                </span>
                <input
                  type="text"
                  value={search}
                  onChange={(e) => { setSearch(e.target.value); setPage(1) }}
                  placeholder={searchPlaceholder}
                  className="martis-resource-search block w-full rounded-md py-2 pl-9 pr-8 text-sm focus:outline-none focus:ring-1"
                  style={{
                    backgroundColor: 'var(--martis-input-bg)',
                    border: '1px solid var(--martis-border)',
                    color: 'var(--martis-text)',
                  }}
                />
                {search && (
                  <button
                    type="button"
                    onClick={() => { setSearch(''); setPage(1) }}
                    className="absolute inset-y-0 right-2 flex items-center"
                    style={{ cursor: 'pointer', background: 'none', border: 'none' }}
                    data-pr-tooltip={tMsg('clear', 'Clear')}
                    data-pr-position="top"
                  >
                    <XIcon size={14} weight="bold" style={{ color: 'var(--martis-danger)' }} />
                  </button>
                )}
              </div>
            )}
            {showCreate && (
              <button
                type="button"
                onClick={() => navigate(createUrl!)}
                className="inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-sm font-medium text-white"
                style={{ backgroundColor: 'var(--martis-accent)' }}
              >
                <PlusIcon size={14} weight="bold" />
                {tAct('create', 'Create')}
              </button>
            )}
            {typeof toolbarExtras === 'function'
              ? toolbarExtras({ selectedRows: selectedRows ?? [] })
              : toolbarExtras}
          </div>
        )}
        {showMeta && (
          <div
            className="martis-relation-meta"
            data-has-trashed={showSoftDeleteToggle ? 'true' : 'false'}
          >
            {showPerPage && (
              <div className="flex items-center gap-2 flex-shrink-0">
                <label className="text-xs martis-text-muted whitespace-nowrap">{tRes('per_page')}:</label>
                <select
                  value={perPage}
                  onChange={(e) => { setPerPage(Number(e.target.value)); setPage(1) }}
                  className="martis-perpage-select"
                >
                  {perPageOptions.map((opt) => (
                    <option key={opt} value={opt}>{opt}</option>
                  ))}
                </select>
              </div>
            )}
            {showSoftDeleteToggle && (
              <div className="flex items-center gap-2 flex-shrink-0">
                <select
                  value={trashed}
                  onChange={(e) => { setTrashed(e.target.value as 'active' | 'with' | 'only'); setPage(1) }}
                  className="martis-perpage-select"
                  data-pr-tooltip={tMsg('trashed_filter_tooltip', 'Soft-delete filter')}
                  data-pr-position="top"
                >
                  <option value="active">{tAct('trashed_active', 'Active')}</option>
                  <option value="with">{tAct('trashed_with', 'With trashed')}</option>
                  <option value="only">{tAct('trashed_only', 'Only trashed')}</option>
                </select>
              </div>
            )}
          </div>
        )}
      </div>

      {!isCollapsed && (
        <>
          <div className="overflow-x-auto">
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
              selectionMode={selectable ? 'multiple' : null}
              selection={selectable ? (selectedRows ?? []) : []}
              onSelectionChange={selectable
                ? (e: DataTableSelectionMultipleChangeEvent<ResourceRecord[]>) =>
                    onSelectionChange?.(e.value)
                : undefined as never}
              rowClassName={(row: ResourceRecord) =>
                row.deleted_at != null ? 'opacity-60' : ''
              }
              emptyMessage={
                <div className="py-8 text-center text-sm" style={{ color: 'var(--martis-text-muted)' }}>
                  {tMsg('no_records_available', 'No records available.')}
                </div>
              }
              className="w-full martis-datatable martis-datatable-striped"
              tableClassName="min-w-full"
            >
              {selectable && (
                <Column selectionMode="multiple" headerStyle={{ width: '3rem' }} />
              )}
              {records.some((r) => r.deleted_at != null) && (
                <Column
                  header=""
                  style={{ width: '5rem' }}
                  body={(row: ResourceRecord) =>
                    row.deleted_at != null ? (
                      <span className="rounded-full bg-red-100 px-2 py-0.5 text-xs font-medium text-red-700 dark:bg-red-900/30 dark:text-red-400">
                        {tMsg('archived', 'Archived')}
                      </span>
                    ) : null
                  }
                />
              )}
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
                        to={viewUrl ? viewUrl(row.id as string | number) : `/resources/${relatedResource}/${row.id}`}
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
              {pivotFields?.map((pf) => (
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
                    const val = pivot?.[pf.attribute] ?? null
                    return <FieldDisplay field={pf} value={val} resourceKey={relatedResource} />
                  }}
                  sortable={false}
                />
              ))}
              {hasActions && (
                <Column
                  header={
                    <span className="text-xs font-medium uppercase tracking-wider text-gray-500">
                      {tAct('actions', 'Actions')}
                    </span>
                  }
                  body={(row: ResourceRecord) => {
                    const isTrashed = row.deleted_at != null
                    return (
                      <div className="flex items-center justify-end gap-1">
                        {showView && (
                          <Link
                            to={viewUrl ? viewUrl(row.id as string | number) : `/resources/${relatedResource}/${row.id}`}
                            className="rounded p-1.5 transition-colors no-underline"
                            style={{ color: 'var(--martis-text-muted)' }}
                            data-pr-tooltip={tAct('view', 'View')}
                            data-pr-position="top"
                            onMouseEnter={(e) => (e.currentTarget.style.color = 'var(--martis-accent)')}
                            onMouseLeave={(e) => (e.currentTarget.style.color = 'var(--martis-text-muted)')}
                          >
                            <EyeIcon size={16} />
                          </Link>
                        )}
                        {!isTrashed && showEdit && (
                          <Link
                            to={editUrl!(row.id as string | number)}
                            className="rounded p-1.5 transition-colors no-underline"
                            style={{ color: 'var(--martis-text-muted)' }}
                            data-pr-tooltip={tAct('edit', 'Edit')}
                            data-pr-position="top"
                            onMouseEnter={(e) => (e.currentTarget.style.color = 'var(--martis-primary)')}
                            onMouseLeave={(e) => (e.currentTarget.style.color = 'var(--martis-text-muted)')}
                          >
                            <PencilSimpleIcon size={16} />
                          </Link>
                        )}
                        {!isTrashed && showDelete && (
                          <button
                            type="button"
                            onClick={() => setDeleteTarget({ id: row.id as string | number })}
                            className="rounded p-1.5 transition-colors"
                            style={{ color: 'var(--martis-text-muted)', background: 'none', border: 'none', cursor: 'pointer' }}
                            data-pr-tooltip={tAct('delete', 'Delete')}
                            data-pr-position="top"
                            onMouseEnter={(e) => (e.currentTarget.style.color = 'var(--martis-danger)')}
                            onMouseLeave={(e) => (e.currentTarget.style.color = 'var(--martis-text-muted)')}
                          >
                            <TrashIcon size={16} />
                          </button>
                        )}
                        {isTrashed && showRestore && (
                          <button
                            type="button"
                            onClick={() => setRestoreTarget({ id: row.id as string | number })}
                            className="rounded p-1.5 transition-colors"
                            style={{ color: 'var(--martis-text-muted)', background: 'none', border: 'none', cursor: 'pointer' }}
                            data-pr-tooltip={tAct('restore', 'Restore')}
                            data-pr-position="top"
                            onMouseEnter={(e) => (e.currentTarget.style.color = 'var(--martis-success)')}
                            onMouseLeave={(e) => (e.currentTarget.style.color = 'var(--martis-text-muted)')}
                          >
                            <ArrowCounterClockwiseIcon size={16} />
                          </button>
                        )}
                        {isTrashed && showForceDelete && (
                          <button
                            type="button"
                            onClick={() => setForceDeleteTarget({ id: row.id as string | number })}
                            className="rounded p-1.5 transition-colors"
                            style={{ color: 'var(--martis-text-muted)', background: 'none', border: 'none', cursor: 'pointer' }}
                            data-pr-tooltip={tAct('force_delete', 'Force delete')}
                            data-pr-position="top"
                            onMouseEnter={(e) => (e.currentTarget.style.color = 'var(--martis-danger)')}
                            onMouseLeave={(e) => (e.currentTarget.style.color = 'var(--martis-text-muted)')}
                          >
                            <SkullIcon size={16} />
                          </button>
                        )}
                        {rowActionsExtras?.(row)}
                      </div>
                    )
                  }}
                  style={{ width: '6rem', textAlign: 'right' }}
                />
              )}
            </DataTable>
          </div>

          {pagination && (
            <Pagination
              currentPage={pagination.current_page}
              lastPage={pagination.last_page}
              total={pagination.total}
              perPage={pagination.per_page ?? perPage}
              from={pagination.from ?? null}
              to={pagination.to ?? null}
              onPageChange={setPage}
            />
          )}
        </>
      )}

      <DeleteModal
        open={deleteTarget !== null}
        resourceLabel={schema?.singularLabel ?? ''}
        isSoftDelete={schema?.softDeletes ?? false}
        onConfirm={async () => {
          if (deleteTarget) {
            await deleteMutation.mutateAsync(deleteTarget.id)
          }
        }}
        onCancel={() => setDeleteTarget(null)}
      />

      <DeleteModal
        open={forceDeleteTarget !== null}
        resourceLabel={schema?.singularLabel ?? ''}
        isSoftDelete={false}
        onConfirm={async () => {
          if (forceDeleteTarget) {
            await forceDeleteMutation.mutateAsync(forceDeleteTarget.id)
          }
        }}
        onCancel={() => setForceDeleteTarget(null)}
      />

      <DeleteModal
        open={restoreTarget !== null}
        resourceLabel={schema?.singularLabel ?? ''}
        isSoftDelete={false}
        variant="restore"
        onConfirm={async () => {
          if (restoreTarget) {
            await restoreMutation.mutateAsync(restoreTarget.id)
          }
        }}
        onCancel={() => setRestoreTarget(null)}
      />

      <style>{`
        .relation-shell-search::placeholder {
          color: var(--martis-text-muted);
          opacity: 0.7;
        }
      `}</style>
    </div>
  )
}
