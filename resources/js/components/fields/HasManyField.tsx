import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Link, useNavigate } from 'react-router-dom'
import { api } from '@/lib/api'
import type { PaginatedResponse, ResourceRecord, ResourceSchema } from '@/types'
import type { FieldDisplayProps, FieldInputProps } from './types'
import { useTranslation } from 'react-i18next'
import { Plus, PencilSimple, Trash, MagnifyingGlass, CaretUp, CaretDown } from '@phosphor-icons/react'

/**
 * HasMany field display — renders an inline DataTable of related records
 * on the parent resource detail page. Supports search, sort, pagination,
 * and CRUD actions (create, edit, delete).
 *
 * Nova v5 parity: detail-only, data fetched via dedicated endpoints.
 */
export function HasManyFieldDisplay({ field }: FieldDisplayProps) {
  const { t: tAct } = useTranslation('actions')
  const { t: tMsg } = useTranslation('messages')
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

  // Fetch related resource schema for column headers
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
    },
  })

  const schema = schemaQuery.data?.data
  const records = recordsQuery.data?.data ?? []
  const pagination = recordsQuery.data?.meta

  const indexFields = schema?.fieldsForIndex ?? []

  function handleSort(attribute: string) {
    if (sort === attribute) {
      setDirection((d) => (d === 'asc' ? 'desc' : 'asc'))
    } else {
      setSort(attribute)
      setDirection('asc')
    }
    setPage(1)
  }

  function handleDelete(relatedId: string | number) {
    if (window.confirm(tMsg('delete_confirm', 'Are you sure?'))) {
      deleteMutation.mutate(relatedId)
    }
  }

  return (
    <div className="mt-6 space-y-3">
      {/* Header */}
      <div className="flex items-center justify-between">
        <h3 className="text-lg font-semibold" style={{ color: 'var(--martis-text)' }}>
          {field.label}
        </h3>
        <div className="flex items-center gap-2">
          {meta?.searchable && (
            <div className="relative">
              <MagnifyingGlass
                size={14}
                className="absolute left-2.5 top-1/2 -translate-y-1/2"
                style={{ color: 'var(--martis-text-muted)' }}
              />
              <input
                type="text"
                value={search}
                onChange={(e) => { setSearch(e.target.value); setPage(1) }}
                placeholder={tMsg('search', 'Search...')}
                className="rounded-md border py-1.5 pl-8 pr-3 text-sm"
                style={{
                  borderColor: 'var(--martis-border)',
                  backgroundColor: 'var(--martis-input-bg)',
                  color: 'var(--martis-text)',
                }}
              />
            </div>
          )}
          {meta?.canCreate && (
            <button
              type="button"
              onClick={() => navigate(
                `/resources/${relatedResource}/create?viaResource=${parentResource}&viaResourceId=${parentId}&viaRelationship=${relationship}`
              )}
              className="inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-sm font-medium text-white"
              style={{ backgroundColor: 'var(--martis-accent)' }}
            >
              <Plus size={14} weight="bold" />
              {tAct('create', 'Create')}
            </button>
          )}
        </div>
      </div>

      {/* Table */}
      <div
        className="overflow-hidden rounded-xl border"
        style={{
          borderColor: 'var(--martis-border)',
          backgroundColor: 'var(--martis-card)',
        }}
      >
        <table className="min-w-full text-sm">
          <thead>
            <tr style={{ borderColor: 'var(--martis-border)' }}>
              {indexFields.map((f) => (
                <th
                  key={f.attribute}
                  onClick={() => f.sortable && handleSort(f.attribute)}
                  className={`px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider ${f.sortable ? 'cursor-pointer select-none' : ''}`}
                  style={{
                    color: 'var(--martis-text-muted)',
                    backgroundColor: 'var(--martis-surface)',
                    borderBottom: '1px solid var(--martis-border)',
                  }}
                >
                  <span className="inline-flex items-center gap-1">
                    {f.label}
                    {sort === f.attribute && (
                      direction === 'asc' ? <CaretUp size={12} weight="bold" /> : <CaretDown size={12} weight="bold" />
                    )}
                  </span>
                </th>
              ))}
              {(meta?.canUpdate || meta?.canDelete) && (
                <th
                  className="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wider"
                  style={{
                    color: 'var(--martis-text-muted)',
                    backgroundColor: 'var(--martis-surface)',
                    borderBottom: '1px solid var(--martis-border)',
                  }}
                >
                  {tAct('actions', 'Actions')}
                </th>
              )}
            </tr>
          </thead>
          <tbody>
            {recordsQuery.isLoading ? (
              Array.from({ length: 3 }).map((_, i) => (
                <tr key={i}>
                  {indexFields.map((f) => (
                    <td key={f.attribute} className="px-4 py-3" style={{ borderBottom: '1px solid var(--martis-border)' }}>
                      <div className="h-4 w-24 animate-pulse rounded" style={{ backgroundColor: 'var(--martis-surface)' }} />
                    </td>
                  ))}
                </tr>
              ))
            ) : records.length === 0 ? (
              <tr>
                <td
                  colSpan={indexFields.length + (meta?.canUpdate || meta?.canDelete ? 1 : 0)}
                  className="px-4 py-8 text-center"
                  style={{ color: 'var(--martis-text-muted)' }}
                >
                  {tMsg('no_records_available', 'No records available.')}
                </td>
              </tr>
            ) : (
              records.map((record) => (
                <tr
                  key={record.id as string | number}
                  className="transition-colors"
                  style={{ borderBottom: '1px solid var(--martis-border)' }}
                  onMouseEnter={(e) => (e.currentTarget.style.backgroundColor = 'var(--martis-hover)')}
                  onMouseLeave={(e) => (e.currentTarget.style.backgroundColor = 'transparent')}
                >
                  {indexFields.map((f) => (
                    <td key={f.attribute} className="px-4 py-3" style={{ color: 'var(--martis-text)' }}>
                      {f.attribute === 'id' ? (
                        <Link
                          to={`/resources/${relatedResource}/${record.id}`}
                          className="font-medium no-underline"
                          style={{ color: 'var(--martis-primary)' }}
                        >
                          {String(record[f.attribute] ?? '')}
                        </Link>
                      ) : (
                        String(record[f.attribute] ?? '')
                      )}
                    </td>
                  ))}
                  {(meta?.canUpdate || meta?.canDelete) && (
                    <td className="px-4 py-3 text-right">
                      <div className="flex items-center justify-end gap-1">
                        {meta?.canUpdate && (
                          <Link
                            to={`/resources/${relatedResource}/${record.id}/edit`}
                            className="rounded p-1 transition-colors no-underline"
                            style={{ color: 'var(--martis-text-muted)' }}
                            onMouseEnter={(e) => (e.currentTarget.style.color = 'var(--martis-primary)')}
                            onMouseLeave={(e) => (e.currentTarget.style.color = 'var(--martis-text-muted)')}
                          >
                            <PencilSimple size={16} />
                          </Link>
                        )}
                        {meta?.canDelete && (
                          <button
                            type="button"
                            onClick={() => handleDelete(record.id as string | number)}
                            className="rounded p-1 transition-colors"
                            style={{ color: 'var(--martis-text-muted)', background: 'none', border: 'none' }}
                            onMouseEnter={(e) => (e.currentTarget.style.color = '#ef4444')}
                            onMouseLeave={(e) => (e.currentTarget.style.color = 'var(--martis-text-muted)')}
                          >
                            <Trash size={16} />
                          </button>
                        )}
                      </div>
                    </td>
                  )}
                </tr>
              ))
            )}
          </tbody>
        </table>

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
              <span>
                {pagination.from}–{pagination.to} / {pagination.total}
              </span>
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
    </div>
  )
}

/**
 * HasMany field input — returns null since HasMany fields don't appear on forms.
 */
export function HasManyFieldInput(_props: FieldInputProps) {
  return null
}
