import { useState, useCallback, useRef, useEffect } from 'react'
import { useParams, useNavigate } from 'react-router-dom'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { api } from '@/lib/api'
import type { PaginatedResponse, ResourceRecord, ResourceSchema, OverrideProps } from '@/types'
import { Table } from '@/components/Table'
import { Pagination } from '@/components/Pagination'
import { DeleteModal } from '@/components/DeleteModal'
import { ActionModal, ActionDropdown, ActionDrawer } from '@/components/Actions'
import type { ActionMeta } from '@/components/Actions'
import { useToast } from '@/contexts/ToastContext'
import { useTranslation } from 'react-i18next'
import { MagnifyingGlass } from '@phosphor-icons/react'
import { ResourceIcon } from '@/components/ResourceIcon'
import { NotFoundPage } from '@/pages/NotFound'
import { componentRegistry } from '@/lib/componentRegistry'
import { MartisLoader } from '@/components/Loader'
import { resolveRedirect } from '@/lib/resolveRedirect'

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
  const [showCreateOverride, setShowCreateOverride] = useState(false)
  const [trashedFilter, setTrashedFilter] = useState<"" | "with" | "only">("")
  const [activeAction, setActiveAction] = useState<ActionMeta | null>(null)
  const [actionDrawer, setActionDrawer] = useState<{ type: 'create' | 'detail'; resource: string; recordId?: string | number } | null>(null)
  // Track whether the current action was triggered inline (single row)
  const inlineActionRef = useRef(false)
  // Track which row IDs the inline action targets (separate from visual selection)
  const [inlineActionRowIds, setInlineActionRowIds] = useState<(string | number)[]>([])

  // Reset selection when navigating between resources
  useEffect(() => {
    setSelectedIds(new Set())
  }, [resource])
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

  // Resolve effective per-page (state overrides schema default)
  const effectivePerPage = perPage ?? schema?.perPage ?? 25

  // Index data
  const indexQuery = useQuery({
    queryKey: ['resources', resource, page, debouncedSearch, sortBy, sortDir, effectivePerPage, trashedFilter],
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
      return api.get<PaginatedResponse<ResourceRecord>>(
        `/api/resources/${resource}?${params.toString()}`,
      )
    },
    enabled: !!resource,
    placeholderData: (prev) => prev,
  })

  // Fetch actions for this resource
  const actionsQuery = useQuery({
    queryKey: ['resource-actions', resource],
    queryFn: () => api.get<{ data: { actions: ActionMeta[] } }>(`/api/resources/${resource}/actions`),
    enabled: !!resource,
  })

  const allActions = actionsQuery.data?.data?.actions ?? []
  const indexActions = allActions.filter((a) => a.showOnIndex && !a.showInline)
  const inlineActions = allActions.filter((a) => a.showInline)
  const standaloneActions = allActions.filter((a) => a.standalone)
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
          {/* Standalone actions (no selection needed) */}
          {standaloneActions.length > 0 && (
            <ActionDropdown
              actions={standaloneActions}
              onSelect={handleActionSelect}
              label={schema.actionsMenuLabel ?? undefined}
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

      {/* Bulk action bar — shown when items are selected (not for inline action temp selection) */}
      {selectedIds.size > 0 && indexActions.length > 0 && (() => {
        // Compute disabled actions: action is disabled if ALL selected rows canRun=false
        const selectedRows = rows.filter(r => selectedIds.has(r.id))
        const bulkDisabledActions = new Set<string>()
        for (const action of indexActions) {
          const allDisabled = selectedRows.length > 0 && selectedRows.every(row => {
            const perAction = (row as Record<string, unknown>)._actionAuthorization as Record<string, boolean> | undefined
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
          <span className="text-sm font-medium" style={{ color: 'var(--martis-text)' }}>
            {tAct('selected_count', { count: selectedIds.size })}
          </span>
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
              type="search"
              placeholder={searchPlaceholder}
              value={search}
              onChange={(e) => handleSearchChange(e.target.value)}
              className="martis-resource-search block w-full rounded-md py-2 pl-9 pr-4 text-sm focus:outline-none focus:ring-1"
              style={{
                backgroundColor: 'var(--martis-input-bg)',
                border: '1px solid var(--martis-border)',
                color: 'var(--martis-text)',
              }}
            />
            <span className="absolute inset-y-0 left-3 flex items-center">
              <MagnifyingGlass size={14} className="martis-text-muted" />
            </span>
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

      {/* Table */}
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
        onClickRow={(row) => { if (row._authorization?.authorizedToView !== false) navigate(`/resources/${resource}/${row.id}`) }}
        resourceKey={resource}
        selectable={selectable}
        inlineActions={inlineActions}
        onInlineAction={handleInlineAction}
        tableConfig={{
          striped: schema.tableStriped,
          showGridlines: schema.tableShowGridlines,
          size: schema.tableSize,
          rowHover: schema.tableRowHover,
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
        />
      )}

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
        />
      )}
    </div>
  )
}


