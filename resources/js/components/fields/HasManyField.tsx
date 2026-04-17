import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Link, useNavigate } from 'react-router-dom'
import { api } from '@/lib/api'
import type { PaginatedResponse, ResourceRecord, ResourceSchema, FieldDefinition } from '@/types'
import type { FieldDisplayProps, FieldInputProps } from './types'
import { FieldDisplay } from '@/components/fields/FieldRenderer'
import { DeleteModal } from '@/components/DeleteModal'
import { ResourceIcon } from '@/components/ResourceIcon'
import { Pagination } from '@/components/Pagination'
import { useTranslation } from 'react-i18next'
import { PlusIcon, EyeIcon, PencilSimpleIcon, TrashIcon, MagnifyingGlassIcon, XIcon, CaretUpIcon, CaretDownIcon, CaretUpDownIcon, CaretRightIcon } from "@phosphor-icons/react"
import { DataTable, type DataTableSortEvent } from 'primereact/datatable'
import { Column } from 'primereact/column'
// Tooltip handled by global <Tooltip> in Layout.tsx

/**
 * HasMany field display — renders differently based on context:
 * - On detail page (value is null): full inline DataTable with CRUD
 * - On index page (value is a count number): compact count badge
 */
export function HasManyFieldDisplay({ field, value }: FieldDisplayProps) {
  // If value is a number, we're on the index page — show count badge
  if (typeof value === 'number') {
    return <HasManyFieldIndexDisplay field={field} value={value} />
  }

  // Otherwise, render the full detail DataTable
  return <HasManyDetailTable field={field} />
}

/**
 * Index display — shows a configurable count badge with optional icon.
 * Configurable via:
 *   ->badgeColor('var(--martis-info)')  — custom badge color
 *   ->badgeIcon('newspaper') — icon next to count
 */
export function HasManyFieldIndexDisplay({ field, value }: FieldDisplayProps) {
  const count = typeof value === 'number' ? value : 0
  const badgeColor = field.badgeColor as string | null
  const badgeIcon = field.badgeIcon as string | null

  return (
    <span
      className="inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-medium"
      style={{
        backgroundColor: badgeColor ? `${badgeColor}15` : 'var(--martis-surface)',
        color: badgeColor ?? 'var(--martis-text)',
        border: `1px solid ${badgeColor ? `${badgeColor}40` : 'var(--martis-border)'}`,
      }}
    >
      {badgeIcon && <ResourceIcon iconName={badgeIcon} size={12} />}
      {count}
    </span>
  )
}

/**
 * Detail display — full DataTable with search, sort, pagination, CRUD.
 * Uses PrimeReact DataTable and FieldDisplay for consistent appearance.
 *
 * P1: showRelationIcon / showRelationCount control header display
 * P2: Uses related resource's searchPlaceholder
 * P5: redirectAfterSave controls navigation after edit/create
 */
function HasManyDetailTable({ field }: { field: FieldDisplayProps['field'] }) {
  const { t: tAct } = useTranslation('actions')
  const { t: tMsg } = useTranslation('messages')
  const { t: tRes } = useTranslation('resources')
  const navigate = useNavigate()
  const qc = useQueryClient()

  const meta = field.hasManyMeta as {
    perPage: number
    perPageOptions: number[]
    searchable: boolean
    canCreate: boolean
    canUpdate: boolean
    canDelete: boolean
  } | undefined

  const relationship = field.relationship as string
  const relatedResource = field.relatedResource as string
  const showRelationIcon = field.showRelationIcon !== false
  const showRelationCount = field.showRelationCount !== false
  const redirectAfterSave = (field.redirectAfterSave as string) ?? 'parent'

  // Extract parent context from the current URL
  const pathParts = window.location.pathname.split('/')
  const resourcesIdx = pathParts.indexOf('resources')
  const parentResource = resourcesIdx >= 0 ? pathParts[resourcesIdx + 1] : ''
  const parentId = resourcesIdx >= 0 ? pathParts[resourcesIdx + 2] : ''

  const [search, setSearch] = useState('')
  const [page, setPage] = useState(1)
  const [perPage, setPerPage] = useState(meta?.perPage ?? 10)
  const [sort, setSort] = useState<string | null>(null)
  const [direction, setDirection] = useState<'asc' | 'desc'>('asc')
  const [deleteTarget, setDeleteTarget] = useState<{ id: string | number } | null>(null)

  // Collapsable panel
  const collapsable = !!field.collapsable
  const collapsedByDefault = !!field.collapsedByDefault
  const [isCollapsed, setIsCollapsed] = useState(collapsedByDefault)

  // Fetch related resource schema for column headers, icon and searchPlaceholder
  const schemaQuery = useQuery({
    queryKey: ['schema', relatedResource],
    queryFn: () => api.get<{ data: ResourceSchema }>(`/api/resources/${relatedResource}/schema`),
    enabled: !!relatedResource,
  })

  // Fetch related records
  const recordsQuery = useQuery({
    queryKey: ['has-many', parentResource, parentId, relationship, { search, page, perPage, sort, direction }],
    queryFn: () => {
      const params = new URLSearchParams()
      if (search) params.set('search', search)
      params.set('page', String(page))
      params.set('per_page', String(perPage))
      if (sort) {
        params.set('sort', sort)
        params.set('direction', direction)
      }
      return api.get<PaginatedResponse<ResourceRecord>>(
        `/api/resources/${parentResource}/${parentId}/has-many/${relationship}?${params.toString()}`
      )
    },
    enabled: !!parentResource && !!parentId && !!relationship,
  })

  const deleteMutation = useMutation({
    mutationFn: (relatedId: string | number) =>
      api.delete(`/api/resources/${parentResource}/${parentId}/has-many/${relationship}/${relatedId}`),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ['has-many', parentResource, parentId, relationship] })
      setDeleteTarget(null)
    },
  })

  const schema = schemaQuery.data?.data
  const records = recordsQuery.data?.data ?? []
  const pagination = recordsQuery.data?.meta
  const totalCount = pagination?.total ?? records.length

  const indexFields: FieldDefinition[] = schema?.fieldsForIndex ?? []
  const hasActions = meta?.canUpdate || meta?.canDelete

  // P2: Use related resource's searchPlaceholder if available
  const searchPlaceholder = (schema as unknown as { searchPlaceholder?: string })?.searchPlaceholder
    ?? tMsg('search', 'Search...')

  // P1: Related resource icon from schema
  const relatedIcon = (schema as unknown as { icon?: string })?.icon

  // Build redirect URL params based on redirectAfterSave mode
  const viaBaseParams = `viaResource=${parentResource}&viaResourceId=${parentId}&viaRelationship=${relationship}`
  const viaParams = `?${viaBaseParams}&redirectMode=${redirectAfterSave}`

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

  return (
    <div className="mt-6 space-y-3">
      {/* Header */}
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <h3
          className={`flex items-center gap-2 text-lg font-semibold ${collapsable ? 'cursor-pointer select-none' : ''}`}
          style={{ color: 'var(--martis-text)' }}
          onClick={collapsable ? () => setIsCollapsed((v) => !v) : undefined}
        >
          {showRelationIcon && relatedIcon && (
            <ResourceIcon iconName={relatedIcon} size={20} />
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
          <span>{field.label}</span>
          {showRelationCount && (
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
          )}
        </h3>
        {!isCollapsed && (
        <div className="flex flex-wrap items-center gap-2">
          {meta?.searchable && (
            <div className="relative">
              <MagnifyingGlassIcon
                size={14}
                className="absolute left-2.5 top-1/2 -translate-y-1/2"
                style={{ color: 'var(--martis-text-muted)' }}
              />
              <input
                type="text"
                value={search}
                onChange={(e) => { setSearch(e.target.value); setPage(1) }}
                placeholder={searchPlaceholder}
                className="has-many-search-input w-full rounded-md border py-1.5 pl-8 pr-8 text-sm sm:w-auto"
                style={{
                  borderColor: 'var(--martis-border)',
                  backgroundColor: 'var(--martis-input-bg)',
                  color: 'var(--martis-text)',
                }}
              />
              {search && (
                <button
                  type="button"
                  onClick={() => { setSearch(''); setPage(1) }}
                  className="absolute inset-y-0 right-2 flex items-center has-many-search-clear"
                  style={{ cursor: 'pointer', background: 'none', border: 'none' }}
                  data-pr-tooltip={tMsg('clear', 'Clear')}
                  data-pr-position="top"
                >
                  <XIcon size={14} weight="bold" style={{ color: 'var(--martis-danger)' }} />
                </button>
              )}
              {/* Tooltip handled by global Layout <Tooltip> */}
            </div>
          )}
          {meta?.canCreate && (
            <button
              type="button"
              onClick={() => navigate(
                `/resources/${relatedResource}/create${viaParams}`
              )}
              className="inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-sm font-medium text-white"
              style={{ backgroundColor: 'var(--martis-accent)' }}
            >
              <PlusIcon size={14} weight="bold" />
              {tAct('create', 'Create')}
            </button>
          )}
          {meta?.perPageOptions && (
            <div className="flex items-center gap-2 flex-shrink-0">
              <label className="text-xs martis-text-muted whitespace-nowrap">{tRes('per_page')}:</label>
              <select
                value={perPage}
                onChange={(e) => { setPerPage(Number(e.target.value)); setPage(1) }}
                className="martis-perpage-select"
              >
                {meta.perPageOptions.map((opt) => (
                  <option key={opt} value={opt}>{opt}</option>
                ))}
              </select>
            </div>
          )}
        </div>
        )}
      </div>

      {!isCollapsed && (
      <>
      {/* DataTable — same PrimeReact DataTable as the index page */}
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
              f.attribute === "id" ? (
                <Link
                  to={`/resources/${relatedResource}/${row.id}`}
                  className="font-medium no-underline"
                  style={{ color: "var(--martis-primary)" }}
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
        {hasActions && (
          <Column
            header={
              <span className="text-xs font-medium uppercase tracking-wider text-gray-500">
                {tAct('actions', 'Actions')}
              </span>
            }
            body={(row: ResourceRecord) => (
              <div className="flex items-center justify-end gap-1">
                <Link
                  to={`/resources/${relatedResource}/${row.id}`}
                  className="rounded p-1.5 transition-colors no-underline"
                  style={{ color: 'var(--martis-text-muted)' }}
                  data-pr-tooltip={tAct('view', 'View')}
                  data-pr-position="top"
                  onMouseEnter={(e) => (e.currentTarget.style.color = 'var(--martis-accent)')}
                  onMouseLeave={(e) => (e.currentTarget.style.color = 'var(--martis-text-muted)')}
                >
                  <EyeIcon size={16} />
                </Link>
                {meta?.canUpdate && (
                  <Link
                    to={`/resources/${relatedResource}/${row.id}/edit${viaParams}`}
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
                {meta?.canDelete && (
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
              </div>
            )}
            style={{ width: '6rem', textAlign: 'right' }}
          />
        )}
      </DataTable>
      </div>

      {/* Pagination */}
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

      {/* Delete confirmation modal */}
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

      {/* Placeholder color fix for light theme */}
      <style>{`
        .has-many-search-input::placeholder {
          color: var(--martis-text-muted);
          opacity: 0.7;
        }
      `}</style>
    </div>
  )
}

/**
 * HasMany field input — returns null since HasMany fields don't appear on forms.
 */
export function HasManyFieldInput(_props: FieldInputProps) {
  return null
}
