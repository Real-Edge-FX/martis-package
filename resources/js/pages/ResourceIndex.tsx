import { useState, useCallback } from 'react'
import { useParams, useNavigate } from 'react-router-dom'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { api } from '@/lib/api'
import type { PaginatedResponse, ResourceRecord, ResourceSchema } from '@/types'
import { Table } from '@/components/Table'
import { Pagination } from '@/components/Pagination'
import { DeleteModal } from '@/components/DeleteModal'
import { useToast } from '@/contexts/ToastContext'
import { useTranslation } from 'react-i18next'

export function ResourceIndexPage() {
  const { resource } = useParams<{ resource: string }>()
  const navigate = useNavigate()
  const qc = useQueryClient()
  const { addToast } = useToast()
  const { t } = useTranslation('resources')
  const { t: tMsg } = useTranslation('messages')
  const { t: tAct } = useTranslation('actions')

  const [page, setPage] = useState(1)
  const [search, setSearch] = useState('')
  const [debouncedSearch, setDebouncedSearch] = useState('')
  const [sortBy, setSortBy] = useState<string | null>(null)
  const [sortDir, setSortDir] = useState<'asc' | 'desc'>('asc')
  const [selectedIds, setSelectedIds] = useState<Set<string | number>>(new Set())
  const [deleteTarget, setDeleteTarget] = useState<ResourceRecord | null>(null)

  // Debounce search
  const handleSearchChange = useCallback((value: string) => {
    setSearch(value)
    clearTimeout((handleSearchChange as { timer?: ReturnType<typeof setTimeout> }).timer)
    ;(handleSearchChange as { timer?: ReturnType<typeof setTimeout> }).timer = setTimeout(() => {
      setDebouncedSearch(value)
      setPage(1)
    }, 300)
  }, [])

  // Schema
  const schemaQuery = useQuery({
    queryKey: ['schema', resource],
    queryFn: () => api.get<{ data: ResourceSchema }>(`/api/resources/${resource}/schema`),
    enabled: !!resource,
  })

  const schema = schemaQuery.data?.data

  // Index data
  const indexQuery = useQuery({
    queryKey: ['resources', resource, page, debouncedSearch, sortBy, sortDir],
    queryFn: () => {
      const params = new URLSearchParams({ page: String(page) })
      if (debouncedSearch) params.set('search', debouncedSearch)
      if (sortBy) {
        params.set('sort', sortBy)
        params.set('direction', sortDir)
      }
      return api.get<PaginatedResponse<ResourceRecord>>(
        `/api/resources/${resource}?${params.toString()}`,
      )
    },
    enabled: !!resource,
    placeholderData: (prev) => prev,
  })

  const deleteMutation = useMutation({
    mutationFn: (id: string | number) => api.delete<{ meta?: { message?: string } }>(`/api/resources/${resource}/${id}`),
    onSuccess: (res) => {
      void qc.invalidateQueries({ queryKey: ['resources', resource] })
      addToast('success', res?.meta?.message ?? tMsg('record_deleted'))
      setDeleteTarget(null)
    },
    onError: () => {
      addToast('error', tMsg('error_delete'))
    },
  })


  function handleSort(attribute: string) {
    if (sortBy === attribute) {
      setSortDir((d) => (d === 'asc' ? 'desc' : 'asc'))
    } else {
      setSortBy(attribute)
      setSortDir('asc')
    }
    setPage(1)
  }

  function handleToggleSelect(id: string | number) {
    setSelectedIds((prev) => {
      const next = new Set(prev)
      if (next.has(id)) next.delete(id)
      else next.add(id)
      return next
    })
  }

  function handleToggleAll() {
    const rows = indexQuery.data?.data ?? []
    const allSelected = rows.every((r) => selectedIds.has(r.id))
    if (allSelected) {
      setSelectedIds(new Set())
    } else {
      setSelectedIds(new Set(rows.map((r) => r.id)))
    }
  }

  if (schemaQuery.isLoading) {
    return <IndexSkeleton />
  }

  if (schemaQuery.isError || !schema) {
    return (
      <div className="rounded-lg border p-6" style={{ borderColor: '#ef4444', backgroundColor: 'var(--martis-surface)', color: '#ef4444' }}>
        {tMsg('error_schema')}
      </div>
    )
  }

  const indexColumns = schema.fields
    .filter((f) => f.showOnIndex)
    .map((field) => ({ field }))

  const rows = indexQuery.data?.data ?? []
  const meta = indexQuery.data?.meta
  const isSoftDelete = schema.softDeletes

  return (
    <div className="space-y-4">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold martis-text">{schema.label}</h1>
          {selectedIds.size > 0 && (
            <p className="text-sm text-indigo-400">
              {t('selected', { count: selectedIds.size })}
            </p>
          )}
        </div>
        <button
          type="button"
          onClick={() => navigate(`/resources/${resource}/create`)}
          className="rounded-lg px-4 py-2 text-sm font-medium text-white hover:opacity-90 focus:outline-none focus:ring-2 focus:ring-indigo-500"
          style={{ backgroundColor: 'var(--martis-accent)' }}
        >
          + {tAct('create')} {schema.singularLabel}
        </button>
      </div>

      {/* Search */}
      <div className="flex items-center gap-3">
        <div className="relative flex-1">
          <input
            type="search"
            placeholder={t('search', { label: schema.label.toLowerCase() })}
            value={search}
            onChange={(e) => handleSearchChange(e.target.value)}
            className="block w-full rounded-md py-2 pl-9 pr-4 text-sm focus:outline-none focus:ring-1"
            style={{
              backgroundColor: 'var(--martis-input-bg)',
              border: '1px solid var(--martis-border)',
              color: 'var(--martis-text)',
            }}
          />
          <span className="absolute inset-y-0 left-3 flex items-center">
            <i className="pi pi-search text-sm martis-text-muted" />
          </span>
        </div>
        {indexQuery.isFetching && (
          <span className="text-xs martis-text-muted">{tMsg('loading')}</span>
        )}
      </div>

      {/* Table */}
      <Table
        columns={indexColumns}
        rows={rows}
        sortBy={sortBy}
        sortDir={sortDir}
        onSort={handleSort}
        selectedIds={selectedIds}
        onToggleSelect={handleToggleSelect}
        onToggleAll={handleToggleAll}
        onClickRow={(row) => navigate(`/resources/${resource}/${row.id}`)}
        resourceKey={resource}
      />

      {/* Pagination */}
      {meta && (
        <Pagination
          currentPage={meta.current_page}
          lastPage={meta.last_page}
          total={meta.total}
          perPage={meta.per_page}
          from={meta.from}
          to={meta.to}
          onPageChange={setPage}
        />
      )}

      {/* Delete modal */}
      <DeleteModal
        open={deleteTarget !== null}
        resourceLabel={schema.singularLabel}
        isSoftDelete={isSoftDelete}
        onConfirm={async () => {
          if (deleteTarget) await deleteMutation.mutateAsync(deleteTarget.id)
        }}
        onCancel={() => setDeleteTarget(null)}
        confirmMessage={isSoftDelete ? schema.messages?.archiveConfirm : schema.messages?.deleteConfirm}
      />
    </div>
  )
}

function IndexSkeleton() {
  return (
    <div className="space-y-4 animate-pulse">
      <div className="h-8 w-48 rounded" style={{ backgroundColor: 'var(--martis-surface)' }} />
      <div className="h-10 w-full rounded" style={{ backgroundColor: 'var(--martis-surface)' }} />
      <div className="rounded-lg border martis-border">
        {Array.from({ length: 5 }).map((_, i) => (
          <div key={i} className="flex gap-4 border-b px-4 py-3" style={{ borderColor: 'var(--martis-border)' }}>
            <div className="h-4 w-4 rounded" style={{ backgroundColor: 'var(--martis-surface)' }} />
            <div className="h-4 flex-1 rounded" style={{ backgroundColor: 'var(--martis-surface)' }} />
            <div className="h-4 w-32 rounded" style={{ backgroundColor: 'var(--martis-surface)' }} />
          </div>
        ))}
      </div>
    </div>
  )
}
