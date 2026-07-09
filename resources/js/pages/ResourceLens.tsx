import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { useNavigate, useParams, useSearchParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { MagnifyingGlassIcon, XIcon, PulseIcon } from '@phosphor-icons/react'
import { api } from '@/lib/api'
import type {
  ActiveFilters,
  FieldDefinition,
  FilterDefinition,
  LensDefinition,
  LensSummaryCell,
  PaginatedResponse,
  ResourceRecord,
  ResourceSchema,
} from '@/types'
import { Table } from '@/components/Table'
import { Pagination } from '@/components/Pagination'
import { DeleteModal } from '@/components/DeleteModal'
import { FilterPanel } from '@/components/FilterPanel'
import { ActionModal, ActionDropdown, ActionDrawer } from '@/components/Actions'
import type { ActionMeta } from '@/components/Actions'
import { LensDropdown } from '@/components/Lens/LensDropdown'
import { NotFoundPage } from '@/pages/NotFound'
import { ResourceErrorPage } from '@/pages/ResourceError'
import { MartisLoader } from '@/components/Loader'
import { useToast } from '@/contexts/ToastContext'
import { usePageTitle } from '@/hooks/usePageTitle'
import { recordHref } from '@/lib/recordHref'
import { filterIndexActions, filterInlineActions } from '@/lib/actionVisibility'

/**
 * Page: `/resources/{resource}/lens/{lens}`.
 *
 * Alternative dataset view of a resource. URL params drive search,
 * filters, sort and page so the view is fully deeplinkable. The
 * lens-specific fields and actions are
 * delivered inside the paginated response's `meta` payload so the UI
 * can render the right columns without a second round-trip.
 */
interface LensMeta {
  current_page: number
  from: number | null
  last_page: number
  per_page: number
  to: number | null
  total: number
  summary?: Record<string, LensSummaryCell>
  fields?: FieldDefinition[]
  actions?: ActionMeta[]
  perPageOptions?: number[]
  polling?: boolean
  pollingInterval?: number
  showPollingToggle?: boolean
  defaultFilters?: Record<string, unknown>
}

type LensResponse = Omit<PaginatedResponse<ResourceRecord>, 'meta'> & { meta: LensMeta }

export function ResourceLensPage() {
  const { resource, lens: lensKey } = useParams<{ resource: string; lens: string }>()
  const navigate = useNavigate()
  const qc = useQueryClient()
  const { addToast } = useToast()
  const [searchParams, setSearchParams] = useSearchParams()
  const { t } = useTranslation('resources')
  const { t: tMsg } = useTranslation('messages')
  const { t: tAct } = useTranslation('actions')

  // ── Schema (needed for label + filter definitions) ────────────────
  const schemaQuery = useQuery({
    queryKey: ['schema', resource],
    queryFn: () => api.get<{ data: ResourceSchema }>(`/api/resources/${resource}/schema`),
    enabled: !!resource,
  })

  const schema = schemaQuery.data?.data
  const lens: LensDefinition | undefined = useMemo(() => {
    if (!schema || !lensKey) return undefined
    return (schema.lenses ?? []).find((l) => l.uriKey === lensKey)
  }, [schema, lensKey])

  usePageTitle(lens && schema ? `${lens.name} · ${schema.label}` : schema?.label ?? null)

  // ── URL-backed view state (D4) ────────────────────────────────────
  const page = Number(searchParams.get('page') ?? '1') || 1
  const search = searchParams.get('search') ?? ''
  const sortBy = searchParams.get('sort') ?? null
  const sortDir = (searchParams.get('direction') ?? 'asc') as 'asc' | 'desc'
  const trashedFilter = (searchParams.get('trashed') ?? '') as '' | 'with' | 'only'

  const filters: ActiveFilters = useMemo(() => {
    const raw = searchParams.get('filters')
    if (!raw) return {}
    try {
      const parsed = JSON.parse(raw)
      return (parsed && typeof parsed === 'object') ? parsed as ActiveFilters : {}
    } catch {
      return {}
    }
  }, [searchParams])

  const mutateParams = useCallback((mutator: (params: URLSearchParams) => void) => {
    setSearchParams((prev) => {
      const next = new URLSearchParams(prev)
      mutator(next)
      return next
    })
  }, [setSearchParams])

  // D3 — seed default filters the first time the URL has none.
  // Uses `replace: true` so this automatic mutation does not push an extra
  // entry to the browser history — otherwise a single back-button press
  // bounces between the seeded and unseeded lens URL instead of actually
  // returning to the resource index.
  const [defaultsSeeded, setDefaultsSeeded] = useState(false)
  useEffect(() => {
    if (defaultsSeeded || !lens) return
    if (!searchParams.has('filters') && lens.defaultFilters && Object.keys(lens.defaultFilters).length > 0) {
      setSearchParams(
        (prev) => {
          const next = new URLSearchParams(prev)
          next.set('filters', JSON.stringify(lens.defaultFilters))
          return next
        },
        { replace: true },
      )
    }
    setDefaultsSeeded(true)
  }, [lens, defaultsSeeded, searchParams, setSearchParams])

  // Debounced search (matches ResourceIndex UX).
  const [searchLocal, setSearchLocal] = useState(search)
  const searchTimer = useRef<ReturnType<typeof setTimeout>>()
  useEffect(() => setSearchLocal(search), [search])
  const handleSearchChange = useCallback((value: string) => {
    setSearchLocal(value)
    clearTimeout(searchTimer.current)
    searchTimer.current = setTimeout(() => {
      mutateParams((p) => {
        if (value) p.set('search', value)
        else p.delete('search')
        p.set('page', '1')
      })
    }, 300)
  }, [mutateParams])

  // ── Data query ─────────────────────────────────────────────────────
  const dataQuery = useQuery<LensResponse>({
    enabled: !!resource && !!lensKey && !!lens,
    queryKey: ['lens', resource, lensKey, page, searchParams.get('per_page'), search, sortBy, sortDir, searchParams.get('filters'), trashedFilter],
    queryFn: () => {
      const params = new URLSearchParams({ page: String(page) })
      const perPageParam = searchParams.get('per_page')
      if (perPageParam) params.set('per_page', perPageParam)
      if (search) params.set('search', search)
      if (sortBy) {
        params.set('sort', sortBy)
        params.set('direction', sortDir)
      }
      const f = searchParams.get('filters')
      if (f) params.set('filters', f)
      if (trashedFilter) params.set('trashed', trashedFilter)
      return api.get<LensResponse>(`/api/resources/${resource}/lenses/${lensKey}?${params.toString()}`)
    },
    refetchInterval: lens?.polling ? Math.max(1, lens.pollingInterval) * 1000 : false,
    placeholderData: (prev) => prev,
  })

  // ── Actions (lens inherits resource actions; handled identically) ─
  const allActions = (dataQuery.data?.meta.actions ?? []) as ActionMeta[]
  const indexActions = filterIndexActions(allActions)
  const inlineActions = filterInlineActions(allActions)
  const standaloneActions = allActions.filter((a) => a.standalone)
  const hasBulkActions = indexActions.length > 0

  const [selectedIds, setSelectedIds] = useState<Set<string | number>>(new Set())
  const [activeAction, setActiveAction] = useState<ActionMeta | null>(null)
  const [actionTargetIds, setActionTargetIds] = useState<(string | number)[]>([])
  const [deleteTarget, setDeleteTarget] = useState<ResourceRecord | null>(null)
  const [actionDrawer, setActionDrawer] = useState<{
    type: 'create' | 'detail' | 'update'
    resource: string
    recordId?: string | number
  } | null>(null)

  const handleToggleSelect = useCallback((id: string | number) => {
    setSelectedIds((prev) => {
      const next = new Set(prev)
      if (next.has(id)) next.delete(id)
      else next.add(id)
      return next
    })
  }, [])
  const handleToggleAll = useCallback(() => {
    setSelectedIds((prev) => {
      const rows = dataQuery.data?.data ?? []
      if (prev.size === rows.length && rows.length > 0) return new Set()
      return new Set(rows.map((r) => r.id))
    })
  }, [dataQuery.data])

  const handleActionSelect = useCallback((action: ActionMeta) => {
    const targets = selectedIds.size > 0 ? Array.from(selectedIds) : []
    setActionTargetIds(targets)
    setActiveAction(action)
  }, [selectedIds])

  const handleInlineAction = useCallback((action: ActionMeta, row: ResourceRecord) => {
    setActionTargetIds([row.id])
    setActiveAction(action)
  }, [])

  const deleteMutation = useMutation({
    mutationFn: (id: string | number) => api.delete<{ meta?: { message?: string } }>(`/api/resources/${resource}/${id}`),
    onSuccess: (res) => {
      void qc.invalidateQueries({ queryKey: ['lens', resource, lensKey] })
      void qc.refetchQueries({ queryKey: ['lens', resource, lensKey], type: 'active' })
      addToast('success', res?.meta?.message ?? tMsg('record_deleted'))
      setDeleteTarget(null)
    },
    onError: (e: unknown) => {
      const err = e as { message?: string }
      addToast('error', err?.message ?? tMsg('error_delete'))
    },
  })

  // ── Early exits ───────────────────────────────────────────────────
  if (!resource || !lensKey) return <NotFoundPage />
  if (schemaQuery.isLoading) return <MartisLoader />
  if (schemaQuery.isError) return <ResourceErrorPage error={schemaQuery.error} />
  if (!schema) return <NotFoundPage />
  if (!lens) return <NotFoundPage />

  const rows = dataQuery.data?.data ?? []
  const meta = dataQuery.data?.meta
  // IMPORTANT: only use `meta.fields` — falling back to the resource fields
  // causes a pre-data flash with the wrong columns that PrimeReact's
  // DataTable memoises aggressively.
  const fields: FieldDefinition[] | null = meta?.fields ?? null
  const columns = (fields ?? []).map((f) => ({ field: f }))
  // The backend already resolves lens → resource fallback, so
  // meta.perPageOptions is authoritative; fall back to the schema only
  // before the first fetch completes.
  const perPageOptions = meta?.perPageOptions ?? schema.perPageOptions ?? [10, 25, 50, 100]
  const effectivePerPage = Number(searchParams.get('per_page') ?? String(perPageOptions[0] ?? 25))
  const summary = meta?.summary
  const filterDefs: FilterDefinition[] = schema.filters ?? []

  // Bulk Actions dropdown — appears in the header next to standalone actions
  // only while at least one row is selected. Hidden otherwise.
  const hasBulk = hasBulkActions && selectedIds.size > 0
  const bulkDropdown = hasBulk ? (
    <ActionDropdown
      actions={indexActions}
      onSelect={handleActionSelect}
      label={schema.bulkActionsMenuLabel ?? undefined}
    />
  ) : null

  // ── Render ─────────────────────────────────────────────────────────
  return (
    <div className="space-y-4">
      {/* Header */}
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-2xl font-bold martis-text flex items-center gap-2">
            {schema.label}
          </h1>
          <p className="text-sm martis-text-muted mt-1 flex items-center gap-2">
            {lens.name}
            {lens.polling && (
              <span
                className="inline-flex items-center gap-1 rounded-full px-1.5 py-0.5 text-[10px] font-medium"
                style={{ backgroundColor: 'var(--martis-success-bg)', color: 'var(--martis-success)' }}
              >
                <PulseIcon size={10} weight="fill" />
                {t('live', 'LIVE')}
              </span>
            )}
          </p>
        </div>
        <div className="flex items-center gap-2">
          <LensDropdown
            lenses={schema.lenses ?? []}
            currentUriKey={lens.uriKey}
            onSelect={(next) => {
              if (next === null) navigate(`/resources/${resource}`)
              else if (next.uriKey !== lens.uriKey) navigate(`/resources/${resource}/lens/${next.uriKey}`)
            }}
          />
          {standaloneActions.length > 0 && (
            <ActionDropdown
              actions={standaloneActions}
              onSelect={handleActionSelect}
              label={schema.actionsMenuLabel ?? undefined}
            />
          )}
          {bulkDropdown}
        </div>
      </div>

      {/* Toolbar + Table + pagination share a single card surface. Bulk
          actions live in the page header (next to standalone actions),
          not inside the toolbar. */}
      <div className="martis-index-surface">
        {(() => {
          const hasFilters = filterDefs.length > 0
          const hasActiveFilters = Object.keys(filters).length > 0

          // Mirror ResourceIndex: a dedicated "Reset filters" toolbar
          // affordance that wipes only the active filter set, leaving
          // sort, search, pagination size and trashed-toggle untouched.
          // Lenses persist filter state in the URL, so we just remove
          // the `filters` searchParam and reset to page 1.
          const resetFiltersButton = hasActiveFilters ? (
            <button
              type="button"
              onClick={() => mutateParams((p) => {
                p.delete('filters')
                p.set('page', '1')
              })}
              className="martis-btn-ghost martis-btn-sm inline-flex items-center gap-1.5"
              data-pr-tooltip={tMsg('reset_filters_tooltip', { defaultValue: 'Clear only the active filters; keep sort, search, and pagination.' })}
              data-pr-position="top"
            >
              <XIcon size={13} weight="bold" />
              {tAct('reset_filters', { defaultValue: 'Reset filters' })}
            </button>
          ) : null

          const filterRow = hasFilters ? (
            <FilterPanel
              key={searchParams.get('filters') ?? '__empty__'}
              filters={filterDefs}
              value={filters}
              onChange={(next) => {
                mutateParams((p) => {
                  if (next && Object.keys(next).length > 0) p.set('filters', JSON.stringify(next))
                  else p.delete('filters')
                  p.set('page', '1')
                })
              }}
              rightSlot={resetFiltersButton}
            />
          ) : null

          return (
            <div className="martis-index-toolbar">
              {filterRow}
              <div className="flex items-center gap-3">
                <div className="relative flex-1">
                  <input
                    type="text"
                    placeholder={schema.searchPlaceholder ?? tMsg('search', 'Search…')}
                    value={searchLocal}
                    onChange={(e) => handleSearchChange(e.target.value)}
                    className="martis-resource-search block w-full rounded-md py-2 pl-9 pr-8 text-sm focus:outline-none focus:ring-1"
                    style={{
                      backgroundColor: 'var(--martis-input-bg)',
                      border: '1px solid var(--martis-border)',
                      color: 'var(--martis-text)',
                    }}
                  />
                  <span className="absolute inset-y-0 left-3 flex items-center">
                    <MagnifyingGlassIcon size={14} className="martis-text-muted" />
                  </span>
                  {searchLocal && (
                    <button
                      type="button"
                      onClick={() => handleSearchChange('')}
                      className="absolute inset-y-0 right-2 flex items-center martis-belongs-to-clear"
                      style={{ cursor: 'pointer', background: 'none', border: 'none' }}
                      data-pr-tooltip={tMsg('clear_search', 'Clear search')}
                      data-pr-position="top"
                      aria-label={tMsg('clear_search', 'Clear search')}
                    >
                      <XIcon size={14} weight="bold" />
                    </button>
                  )}
                </div>

                <div className="flex items-center gap-2 flex-shrink-0">
                  <label className="text-xs martis-text-muted whitespace-nowrap">{t('per_page', 'Per page')}:</label>
                  <select
                    value={effectivePerPage}
                    onChange={(e) => mutateParams((p) => {
                      p.set('per_page', e.target.value)
                      p.set('page', '1')
                    })}
                    className="martis-perpage-select"
                  >
                    {perPageOptions.map((opt) => (
                      <option key={opt} value={opt}>{opt}</option>
                    ))}
                  </select>
                </div>

                {schema.softDeletes && (
                  <div className="flex items-center gap-2 flex-shrink-0">
                    <select
                      value={trashedFilter}
                      onChange={(e) => {
                        const next = e.target.value as '' | 'with' | 'only'
                        mutateParams((p) => {
                          if (next) p.set('trashed', next)
                          else p.delete('trashed')
                          p.set('page', '1')
                        })
                      }}
                      className="martis-perpage-select"
                    >
                      <option value="">{t('trashed_active', 'Active')}</option>
                      <option value="with">{t('trashed_with', 'With trashed')}</option>
                      <option value="only">{t('trashed_only', 'Only trashed')}</option>
                    </select>
                  </div>
                )}
              </div>
            </div>
          )
        })()}

      <MartisLoader loading={dataQuery.isFetching} overlay>
        {fields === null ? (
          <div className="py-12 text-center text-sm" style={{ color: 'var(--martis-text-muted)' }}>
            {tMsg('loading', 'Loading…')}
          </div>
        ) : (
        <Table
          key={fields.map((f) => f.attribute).join('|')}
          columns={columns}
          rows={rows}
          sortBy={sortBy}
          sortDir={sortDir}
          onSort={(attr) => {
            mutateParams((p) => {
              const alreadySorted = attr === sortBy
              const nextDir = alreadySorted && sortDir === 'asc' ? 'desc' : 'asc'
              p.set('sort', attr)
              p.set('direction', nextDir)
            })
          }}
          selectedIds={selectedIds}
          onToggleSelect={handleToggleSelect}
          onToggleAll={handleToggleAll}
          onClickRow={schema.rowClickOpensDetail === false ? undefined : (row) => {
            if (row._authorization?.authorizedToView === false) return
            if (schema.overrides?.detail) {
              setActionDrawer({ type: 'detail', resource: resource!, recordId: row.id })
            } else {
              navigate(recordHref(resource!, row.id))
            }
          }}
          resourceKey={resource}
          selectable={hasBulkActions}
          actionsColumnLabel={schema.actionsColumnLabel}
          inlineActions={inlineActions}
          onInlineAction={handleInlineAction}
          defaultRowActions={schema.defaultRowActions}
          onDefaultView={(row) => {
            if (schema.overrides?.detail) {
              setActionDrawer({ type: 'detail', resource: resource!, recordId: row.id })
            } else {
              navigate(recordHref(resource!, row.id))
            }
          }}
          onDefaultEdit={(row) => {
            if (schema.overrides?.update) {
              setActionDrawer({ type: 'update', resource: resource!, recordId: row.id })
            } else {
              navigate(`/resources/${resource}/${row.id}/edit`)
            }
          }}
          onDefaultDelete={(row) => setDeleteTarget(row)}
          tableConfig={{
            striped: schema.tableStriped,
            showGridlines: schema.tableShowGridlines,
            size: schema.tableSize,
            rowHover: schema.tableRowHover,
            layout: schema.tableLayout,
          }}
        />
        )}
      </MartisLoader>

      {/* Summary row — Martis D1 */}
      {summary && Object.keys(summary).length > 0 && (
        <div
          className="flex flex-wrap gap-6 border-t px-4 py-3 text-sm"
          style={{
            borderColor: 'var(--martis-border)',
            backgroundColor: 'var(--martis-surface-alt)',
            color: 'var(--martis-text)',
          }}
          data-testid="lens-summary-row"
        >
          {Object.entries(summary).map(([key, cell]) => (
            <div key={key} className="flex flex-col">
              <span style={{ color: 'var(--martis-text-muted)' }} className="text-xs">{cell.label}</span>
              <span className="font-semibold">{String(cell.value ?? '')}</span>
            </div>
          ))}
        </div>
      )}

      {/* Pagination */}
      {meta && (
        <Pagination
          currentPage={meta.current_page}
          lastPage={meta.last_page}
          total={meta.total}
          perPage={meta.per_page}
          from={meta.from}
          to={meta.to}
          onPageChange={(next) => mutateParams((p) => p.set('page', String(next)))}
          selectedCount={selectedIds.size}
          itemLabel={schema.label.toLowerCase()}
        />
      )}
      </div>

      {/* Delete confirmation */}
      <DeleteModal
        open={!!deleteTarget}
        resourceLabel={schema.label}
        isSoftDelete={schema.softDeletes === true}
        onCancel={() => setDeleteTarget(null)}
        onConfirm={async () => {
          if (!deleteTarget) return
          await deleteMutation.mutateAsync(deleteTarget.id)
        }}
      />

      {/* Action runner */}
      <ActionModal
        action={activeAction}
        resource={resource}
        selectedIds={actionTargetIds}
        visible={!!activeAction}
        onHide={() => {
          setActiveAction(null)
          setActionTargetIds([])
        }}
        onSuccess={() => {
          void qc.invalidateQueries({ queryKey: ['lens', resource, lensKey] })
          void qc.refetchQueries({ queryKey: ['lens', resource, lensKey], type: 'active' })
          setSelectedIds(new Set())
        }}
        onOpenCreate={(res) => setActionDrawer({ type: 'create', resource: res })}
        onOpenDetail={(res, rid) => setActionDrawer({ type: 'detail', resource: res, recordId: rid })}
        onOpenUpdate={(res, rid) => setActionDrawer({ type: 'update', resource: res, recordId: rid })}
      />

      {/* Drawer for detail/update/create overrides */}
      {actionDrawer && (
        <ActionDrawer
          type={actionDrawer.type}
          resource={actionDrawer.resource}
          recordId={actionDrawer.recordId}
          onClose={() => setActionDrawer(null)}
          onSuccess={() => {
            // The drawer already invalidates ['resources', resource]; the lens
            // uses a different prefix so we must invalidate + refetch the lens
            // queries explicitly to reflect the update in the list.
            void qc.invalidateQueries({ queryKey: ['lens', resource, lensKey] })
            void qc.refetchQueries({ queryKey: ['lens', resource, lensKey], type: 'active' })
            setActionDrawer(null)
          }}
          onSwitchTo={(next) => setActionDrawer(next)}
        />
      )}
    </div>
  )
}
