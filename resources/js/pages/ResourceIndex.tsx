import { useState, useCallback, useRef, useEffect } from 'react'
import { useParams, useNavigate } from 'react-router-dom'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { api } from '@/lib/api'
import type { PaginatedResponse, ResourceRecord, ResourceSchema, OverrideProps, ActiveFilters } from '@/types'
import { Table } from '@/components/Table'
import { Pagination } from '@/components/Pagination'
import { DeleteModal } from '@/components/DeleteModal'
import { ActionModal, ActionDropdown, ActionDrawer } from '@/components/Actions'
import type { ActionMeta } from '@/components/Actions'
import { useToast } from '@/contexts/ToastContext'
import { useTranslation } from 'react-i18next'
import { MagnifyingGlassIcon, XIcon } from "@phosphor-icons/react"
import { ResourceIcon } from '@/components/ResourceIcon'
import { NotFoundPage } from '@/pages/NotFound'
import { componentRegistry } from '@/lib/componentRegistry'
import { MartisLoader } from '@/components/Loader'
import { FilterPanel } from '@/components/FilterPanel'
import { LensDropdown } from '@/components/Lens/LensDropdown'
import { resolveRedirect } from '@/lib/resolveRedirect'
import { usePageTitle } from '@/hooks/usePageTitle'

export function ResourceIndexPage() {
  const { resource } = useParams<{ resource: string }>()
  const navigate = useNavigate()
  const qc = useQueryClient()
  const { addToast } = useToast()
  const { t } = useTranslation('resources')
  const { t: tMsg } = useTranslation('messages')
  const { t: tAct } = useTranslation('actions')

  const [page, setPage] = useState(1)
  const [perPage, setPerPage] = useState<number | null>(null)
  const [search, setSearch] = useState('')
  const [debouncedSearch, setDebouncedSearch] = useState('')
  const [sortBy, setSortBy] = useState<string | null>(null)
  const [sortDir, setSortDir] = useState<'asc' | 'desc'>('asc')
  const [selectedIds, setSelectedIds] = useState<Set<string | number>>(new Set())
  const [deleteTarget, setDeleteTarget] = useState<ResourceRecord | null>(null)
  const [restoreTarget, setRestoreTarget] = useState<ResourceRecord | null>(null)
  const [forceDeleteTarget, setForceDeleteTarget] = useState<ResourceRecord | null>(null)
  const [showCreateOverride, setShowCreateOverride] = useState(false)
  const [trashedFilter, setTrashedFilter] = useState<"" | "with" | "only">("")
  const [activeFilters, setActiveFilters] = useState<ActiveFilters>({})
  const [activeAction, setActiveAction] = useState<ActionMeta | null>(null)
  const [actionDrawer, setActionDrawer] = useState<{ type: 'create' | 'detail' | 'update'; resource: string; recordId?: string | number } | null>(null)
  // Track whether the current action was triggered inline (single row)
  const inlineActionRef = useRef(false)
  // Timer ref for debounced search (to cancel on resource change)
  const searchTimerRef = useRef<ReturnType<typeof setTimeout>>()
  // Track which row IDs the inline action targets (separate from visual selection)
  const [inlineActionRowIds, setInlineActionRowIds] = useState<(string | number)[]>([])

  // Reset view state when navigating between resources. Without this,
  // page/sort/filter state leaks across resources (e.g. opening Projects
  // after browsing Clients page 2 would also open on page 2).
  useEffect(() => {
    setSelectedIds(new Set())
    setSearch('')
    setDebouncedSearch('')
    setActiveFilters({})
    setPage(1)
    setPerPage(null)
    setSortBy(null)
    setSortDir('asc')
    setTrashedFilter('')
    clearTimeout(searchTimerRef.current)
  }, [resource])
  // Debounce search
  const handleSearchChange = useCallback((value: string) => {
    setSearch(value)
    clearTimeout(searchTimerRef.current)
    searchTimerRef.current = setTimeout(() => {
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

  usePageTitle(schema?.label ?? null)

  // Resolve effective per-page (state overrides schema default)
  const effectivePerPage = perPage ?? schema?.perPage ?? 25

  // Index data
  const indexQuery = useQuery({
    queryKey: ['resources', resource, page, debouncedSearch, sortBy, sortDir, effectivePerPage, trashedFilter, activeFilters],
    queryFn: () => {
      const params = new URLSearchParams({
        page: String(page),
        per_page: String(effectivePerPage),
      })
      if (debouncedSearch) params.set('search', debouncedSearch)
      if (trashedFilter) params.set('trashed', trashedFilter)
      if (sortBy) {
        params.set('sort', sortBy)
        params.set('direction', sortDir)
      }
      if (Object.keys(activeFilters).length > 0) {
        params.set('filters', JSON.stringify(activeFilters))
      }
      return api.get<PaginatedResponse<ResourceRecord>>(
        `/api/resources/${resource}?${params.toString()}`,
      )
    },
    enabled: !!resource,
    // Keep previous data only when paginating/searching within the same resource
    // (not when navigating to a different resource — that would show stale columns/data)
    placeholderData: (prev, prevQuery) => {
      const prevKey = prevQuery?.queryKey
      if (Array.isArray(prevKey) && prevKey[1] === resource) return prev
      return undefined
    },
  })

  // Actions are included in the schema payload — no separate query needed.
  // This eliminates the "inline actions flash" on refresh where buttons
  // briefly disappear while a secondary query loads.
  const allActions = (schemaQuery.data?.data?.actions ?? []) as ActionMeta[]
  const indexActions = allActions.filter((a) => a.showOnIndex && !a.showInline)
  const inlineActions = allActions.filter((a) => a.showInline)
  const standaloneActions = allActions.filter((a) => a.standalone)
  const standaloneDisabledActions = new Set<string>(
    standaloneActions
      .filter(a => a.destructive
        ? schema?.authorization?.authorizedToRunDestructiveAction === false
        : schema?.authorization?.authorizedToRunAction === false)
      .map(a => a.uriKey)
  )
  const hasActions = indexActions.length > 0

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

  const restoreMutation = useMutation({
    mutationFn: (id: string | number) => api.put<{ meta?: { message?: string } }>(`/api/resources/${resource}/${id}/restore`),
    onSuccess: (res) => {
      void qc.invalidateQueries({ queryKey: ['resources', resource] })
      addToast('success', res?.meta?.message ?? tMsg('record_restored', 'Record restored.'))
      setRestoreTarget(null)
    },
    onError: () => {
      addToast('error', tMsg('error_restore', 'Failed to restore record.'))
    },
  })

  const forceDeleteMutation = useMutation({
    mutationFn: (id: string | number) => api.delete<{ meta?: { message?: string } }>(`/api/resources/${resource}/${id}/force`),
    onSuccess: (res) => {
      void qc.invalidateQueries({ queryKey: ['resources', resource] })
      addToast('success', res?.meta?.message ?? tMsg('record_deleted'))
      setForceDeleteTarget(null)
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
    const allSelected = rows.length > 0 && rows.every((r) => selectedIds.has(r.id))
    if (allSelected) {
      setSelectedIds(new Set())
    } else {
      setSelectedIds(new Set(rows.map((r) => r.id)))
    }
  }

  function handlePerPageChange(value: number) {
    setPerPage(value)
    setPage(1)
  }

  function handleActionSelect(action: ActionMeta) {
    inlineActionRef.current = false
    setActiveAction(action)
  }

  function handleInlineAction(action: ActionMeta, row: { id: string | number }) {
    // Track the target row separately — do NOT modify selectedIds to avoid toggling checkboxes
    inlineActionRef.current = true
    setInlineActionRowIds([row.id])
    setActiveAction(action)
  }

  function handleActionHide() {
    if (inlineActionRef.current) {
      inlineActionRef.current = false
      setInlineActionRowIds([])
    }
    setActiveAction(null)
  }

  function handleSetSelection(ids: Set<string | number>) {
    setSelectedIds(ids)
  }

  function handleActionSuccess() {
    void qc.invalidateQueries({ queryKey: ['resources', resource] })
    setSelectedIds(new Set())
    setActiveAction(null)
    inlineActionRef.current = false
    setInlineActionRowIds([])
  }

  /** Build standardized OverrideProps for any override rendered from this page. */
  function buildOverrideProps(overrideDef: { component: string; params: Record<string, unknown>; redirectAfter?: string | null }, extra?: Partial<OverrideProps>): OverrideProps {
    return {
      schema: schema!,
      resource: resource!,
      params: overrideDef.params ?? {},
      record: null,
      recordId: null,
      navigate: (to: string) => navigate(to),
      onClose: () => {
        setShowCreateOverride(false)
      },
      onCreated: (rec) => {
        setShowCreateOverride(false)
        void qc.invalidateQueries({ queryKey: ['resources', resource] })
        addToast('success', schema!.messages?.created ?? 'Record created successfully.')
        const target = resolveRedirect(overrideDef.redirectAfter, resource!, rec.id)
        if (target) navigate(target)
      },
      onUpdated: (rec) => {
        void qc.invalidateQueries({ queryKey: ['resources', resource] })
        addToast('success', schema!.messages?.updated ?? 'Record updated successfully.')
        const target = resolveRedirect(overrideDef.redirectAfter, resource!, rec.id)
        if (target) navigate(target)
      },
      onDeleted: () => {
        void qc.invalidateQueries({ queryKey: ['resources', resource] })
        addToast('success', schema!.messages?.deleted ?? 'Record deleted successfully.')
      },
      onEdit: (id) => { if (id) navigate(`/resources/${resource}/${id}/edit`) },
      onView: (id) => navigate(`/resources/${resource}/${id}`),
      addToast,
      ...extra,
    }
  }

  if (schemaQuery.isLoading) {
    return (
      <div className="flex items-center justify-center py-20">
        <MartisLoader loading size="lg" />
      </div>
    )
  }

  if (schemaQuery.isError || !schema) {
    return <NotFoundPage />
  }

  // Check for index override — replaces the entire index page
  if (schema.overrides?.index) {
    const OverrideComponent = componentRegistry.resolve(schema.overrides.index.component)
    if (OverrideComponent) {
      const C = OverrideComponent as React.ComponentType<OverrideProps>
      return <C {...buildOverrideProps(schema.overrides.index)} />
    }
  }

  const indexColumns = (schema.fieldsForIndex ?? [])
    .map((field) => ({ field }))

  const rows = indexQuery.data?.data ?? []
  const meta = indexQuery.data?.meta
  const isSoftDelete = schema.softDeletes
  const perPageOptions = schema.perPageOptions ?? [10, 25, 50, 100]
  const showSearch = schema.indexSearchable !== false
  const searchPlaceholder = schema.searchPlaceholder || t('search', { label: schema.label.toLowerCase() })
  const selectable = hasActions

  return (
    <div className="space-y-4">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold martis-text flex items-center gap-2">
            <ResourceIcon iconName={(schema.icon)} size={24} />
            {schema.label}
          </h1>
          {schema.subtitle && (
            <p className="text-sm martis-text-muted mt-1">{schema.subtitle}</p>
          )}
        </div>
        <div className="flex items-center gap-2">
          {/* Lens selector */}
          {Array.isArray(schema.lenses) && schema.lenses.length > 0 && (
            <LensDropdown
              lenses={schema.lenses}
              currentUriKey={null}
              onSelect={(next) => {
                if (next) navigate(`/resources/${resource}/lens/${next.uriKey}`)
              }}
            />
          )}
          {/* Standalone actions (no selection needed) */}
          {standaloneActions.length > 0 && (
            <ActionDropdown
              actions={standaloneActions}
              onSelect={handleActionSelect}
              label={schema.actionsMenuLabel ?? undefined}
              disabledActions={standaloneDisabledActions}
            />
          )}
          {schema.authorization?.authorizedToCreate !== false && (
          <button
            type="button"
            onClick={() => {
              if (schema.overrides?.create) {
                setShowCreateOverride(true)
              } else {
                navigate(`/resources/${resource}/create`)
              }
            }}
            className="rounded-lg px-4 py-2 text-sm font-medium text-white hover:opacity-90 focus:outline-none focus:ring-2 focus:ring-indigo-500"
            style={{ backgroundColor: 'var(--martis-accent)' }}
          >
            + {tAct('create')} {schema.singularLabel}
          </button>
          )}
        </div>
      </div>

      {/* Filter panel — shown when resource has filters */}
      {schema.filters && schema.filters.length > 0 && (
        <FilterPanel
          filters={schema.filters}
          value={activeFilters}
          onChange={(filters) => { setActiveFilters(filters); setPage(1) }}
        />
      )}

      {/* Bulk action bar — shown when items are selected (not for inline action temp selection) */}
      {selectedIds.size > 0 && indexActions.length > 0 && (() => {
        // Compute disabled actions: action is disabled if ALL selected rows canRun=false
        const selectedRows = rows.filter(r => selectedIds.has(r.id))
        const bulkDisabledActions = new Set<string>()
        for (const action of indexActions) {
          // Sole actions are only available when exactly 1 record is selected
          if (action.sole && selectedIds.size > 1) {
            bulkDisabledActions.add(action.uriKey)
            continue
          }
          const allDisabled = selectedRows.length > 0 && selectedRows.every(row => {
            const perAction = row._actionAuthorization
            if (perAction && action.uriKey in perAction) return !perAction[action.uriKey]
            return false
          })
          if (allDisabled) bulkDisabledActions.add(action.uriKey)
        }
        return (
        <div
          className="flex items-center gap-3 rounded-lg border px-4 py-2"
          style={{
            backgroundColor: 'var(--martis-surface)',
            borderColor: 'var(--martis-accent)',
          }}
        >
          {/* Selected count lives in the table footer ("N selected · X of Y")
              per the design-system spec — no duplication at the top. */}
          <ActionDropdown
            actions={indexActions}
            onSelect={handleActionSelect}
            label={schema.bulkActionsMenuLabel || schema.actionsMenuLabel || tAct('bulk_actions')}
            disabledActions={bulkDisabledActions}
          />
          <button
            type="button"
            onClick={() => setSelectedIds(new Set())}
            className="ml-auto text-xs"
            style={{ color: 'var(--martis-text-muted)' }}
          >
            {tAct('cancel')}
          </button>
        </div>
        )
      })()}

      {/* Search + Per Page controls */}
      <div className="flex items-center gap-3">
        {showSearch && (
          <div className="relative flex-1">
            <input
              type="text"
              placeholder={searchPlaceholder}
              value={search}
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
            {search && (
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
        )}

        {/* Per-page selector */}
        <div className="flex items-center gap-2 flex-shrink-0">
          <label className="text-xs martis-text-muted whitespace-nowrap">{t('per_page')}:</label>
          <select
            value={effectivePerPage}
            onChange={(e) => handlePerPageChange(Number(e.target.value))}
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
              onChange={(e) => { setTrashedFilter(e.target.value as "" | "with" | "only"); setPage(1) }}
              className="martis-perpage-select"
            >
              <option value="">{t("trashed_active")}</option>
              <option value="with">{t("trashed_with")}</option>
              <option value="only">{t("trashed_only")}</option>
            </select>
          </div>
        )}

        {indexQuery.isFetching && (
          <MartisLoader loading size="sm" />
        )}
      </div>

      {/* Table + pagination share a single card surface so the footer
          reads as the last row of the table, matching the design-system
          spec instead of floating below. */}
      <div className="martis-index-surface">
      <MartisLoader loading={indexQuery.isFetching && !indexQuery.isPlaceholderData} overlay>
      <Table
        columns={indexColumns}
        rows={rows}
        sortBy={sortBy}
        sortDir={sortDir}
        onSort={handleSort}
        selectedIds={selectedIds}
        onToggleSelect={handleToggleSelect}
        onToggleAll={handleToggleAll}
        onSetSelection={handleSetSelection}
        onClickRow={schema.rowClickOpensDetail === false ? undefined : (row) => {
          if (row._authorization?.authorizedToView === false) return
          // Always navigate — see `onDefaultView` for the rationale. Short
          // version: drawer overlay needs the record id in the URL so
          // nested has-many/morph-many queries can resolve their parent.
          navigate(`/resources/${resource}/${row.id}`)
        }}
        resourceKey={resource}
        selectable={selectable}
        actionsColumnLabel={schema.actionsColumnLabel}
        inlineActions={inlineActions}
        onInlineAction={handleInlineAction}
        defaultRowActions={schema.defaultRowActions}
        onDefaultView={(row) => {
          // Always navigate to the detail URL. When a drawer detail override
          // exists, the ResourceDetailPage renders the index as backdrop
          // plus the drawer on top — same visual, but now the URL carries
          // the record id which the nested relationship fields parse to
          // fire their has-many queries.
          navigate(`/resources/${resource}/${row.id}`)
        }}
        onDefaultEdit={(row) => {
          if (schema.overrides?.update) {
            setActionDrawer({ type: 'update', resource: resource!, recordId: row.id })
          } else {
            navigate(`/resources/${resource}/${row.id}/edit`)
          }
        }}
        onDefaultDelete={(row) => setDeleteTarget(row)}
        onDefaultRestore={(row) => setRestoreTarget(row)}
        onDefaultForceDelete={(row) => setForceDeleteTarget(row)}
        tableConfig={{
          striped: schema.tableStriped,
          showGridlines: schema.tableShowGridlines,
          size: schema.tableSize,
          rowHover: schema.tableRowHover,
          layout: schema.tableLayout,
        }}
      />
      </MartisLoader>

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
          selectedCount={selectedIds.size}
          itemLabel={schema.label.toLowerCase()}
        />
      )}
      </div>

      {/* Create override overlay — full standardized props */}
      {showCreateOverride && schema.overrides?.create && (() => {
        const OverrideComponent = componentRegistry.resolve(schema.overrides.create.component)
        if (!OverrideComponent) return null
        const C = OverrideComponent as React.ComponentType<OverrideProps>
        return <C {...buildOverrideProps(schema.overrides.create)} />
      })()}

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

      {/* Restore modal */}
      <DeleteModal
        open={restoreTarget !== null}
        resourceLabel={schema.singularLabel}
        isSoftDelete={false}
        variant="restore"
        onConfirm={async () => {
          if (restoreTarget) await restoreMutation.mutateAsync(restoreTarget.id)
        }}
        onCancel={() => setRestoreTarget(null)}
      />

      {/* Force-delete modal */}
      <DeleteModal
        open={forceDeleteTarget !== null}
        resourceLabel={schema.singularLabel}
        isSoftDelete={false}
        onConfirm={async () => {
          if (forceDeleteTarget) await forceDeleteMutation.mutateAsync(forceDeleteTarget.id)
        }}
        onCancel={() => setForceDeleteTarget(null)}
      />

      {/* Action execution modal */}
      <ActionModal
        resource={resource!}
        action={activeAction}
        selectedIds={activeAction?.standalone ? [] : inlineActionRowIds.length > 0 ? inlineActionRowIds : Array.from(selectedIds)}
        visible={activeAction !== null}
        onHide={handleActionHide}
        onSuccess={handleActionSuccess}
        onOpenCreate={(res) => setActionDrawer({ type: "create", resource: res })}
        onOpenDetail={(res, rid) => setActionDrawer({ type: "detail", resource: res, recordId: rid })}
        onOpenUpdate={(res, rid) => setActionDrawer({ type: "update", resource: res, recordId: rid })}
      />

      {actionDrawer && (
        <ActionDrawer
          type={actionDrawer.type}
          resource={actionDrawer.resource}
          recordId={actionDrawer.recordId}
          onClose={() => setActionDrawer(null)}
          onSuccess={() => {
            void qc.invalidateQueries({ queryKey: ["resources", resource] })
            setActionDrawer(null)
          }}
          onSwitchTo={(next) => setActionDrawer(next)}
        />
      )}
    </div>
  )
}
