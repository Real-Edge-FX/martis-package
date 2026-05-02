import { useState, useCallback, useRef, useEffect } from 'react'
import { useParams, useNavigate, useLocation, useNavigationType } from 'react-router-dom'
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
import { ResourceErrorPage } from '@/pages/ResourceError'
import { componentRegistry } from '@/lib/componentRegistry'
import { MartisLoader } from '@/components/Loader'
import { FilterPanel } from '@/components/FilterPanel'
import { LensDropdown } from '@/components/Lens/LensDropdown'
import { resolveRedirect } from '@/lib/resolveRedirect'
import { usePageTitle } from '@/hooks/usePageTitle'
import { useResourceAccent } from '@/lib/useResourceAccent'
import { useResourceLoaderConfig } from '@/contexts/LoaderConfigContext'
import { readStickyView, useStickyView, clearStickyView } from '@/lib/useStickyView'
import { ArrowsClockwiseIcon } from '@phosphor-icons/react'

export function ResourceIndexPage() {
  const { resource } = useParams<{ resource: string }>()
  const navigate = useNavigate()
  const location = useLocation()
  // POP = back/forward/initial mount, PUSH = link click, REPLACE = our own
  // hydration-time URL strip. Drives the sticky-vs-clean decision below.
  const navigationType = useNavigationType()
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
  // Tracks whether the current view state was set from URL parameters
  // or PUSH-style navigation rather than from sticky storage / user UI
  // edits. While true, sticky writes are paused so a transient deep-link
  // (e.g. `MenuItem::filter()->applies(...)`) doesn't overwrite the
  // user's saved view. Any handler that the user wires into the UI flips
  // this back to false via `markUserDriven()`.
  const [viewIsUrlDriven, setViewIsUrlDriven] = useState(false)
  const markUserDriven = useCallback(() => setViewIsUrlDriven(false), [])
  // Controls the FilterPanel collapsed/expanded affordance. Tracked in
  // ResourceIndex (rather than inside FilterPanel) so it can be
  // persisted per-resource via sticky views — without this, opening
  // the filters on Clients leaked the expanded state into Projects
  // when navigating between resources because FilterPanel stayed
  // mounted across the route change.
  const [filtersOpen, setFiltersOpen] = useState(false)
  const [activeAction, setActiveAction] = useState<ActionMeta | null>(null)
  const [actionDrawer, setActionDrawer] = useState<{ type: 'create' | 'detail' | 'update'; resource: string; recordId?: string | number } | null>(null)
  // Track whether the current action was triggered inline (single row)
  const inlineActionRef = useRef(false)
  // Timer ref for debounced search (to cancel on resource change)
  const searchTimerRef = useRef<ReturnType<typeof setTimeout>>()
  // Track which row IDs the inline action targets (separate from visual selection)
  const [inlineActionRowIds, setInlineActionRowIds] = useState<(string | number)[]>([])

  // Restore sticky view state when navigating between resources, or
  // fall back to defaults when no saved state exists. Each resource
  // gets its own sessionStorage entry (`martis:view:{uriKey}`) so
  // page / sort / filter state never leaks across resources, but
  // navigating to a record's detail and clicking back DOES preserve
  // the view. See `lib/useStickyView.ts`.
  useEffect(() => {
    setSelectedIds(new Set())
    clearTimeout(searchTimerRef.current)

    if (!resource) return

    // ?search=… in the URL wins over sticky view on mount. Used by the
    // Cmd+K palette's "View all" footer to land on the index with the
    // query already applied. We strip the param after reading so the
    // user's later manual edits don't get overridden by browser back/
    // forward navigation. Other URL params keep their existing flow.
    const initialUrlParams = new URLSearchParams(location.search)
    const urlSearch = initialUrlParams.get('search') ?? ''
    if (urlSearch) {
      setViewIsUrlDriven(true)
      setSearch(urlSearch)
      setDebouncedSearch(urlSearch)
      setActiveFilters({})
      setPage(1)
      setPerPage(null)
      setSortBy(null)
      setSortDir('asc')
      setTrashedFilter('')
      setFiltersOpen(false)

      initialUrlParams.delete('search')
      const next = initialUrlParams.toString()
      window.history.replaceState(
        {},
        '',
        window.location.pathname + (next ? `?${next}` : '') + window.location.hash,
      )
      return
    }

    // ?filters={"<uriKey>":<value>,...} in the URL wins over sticky view on
    // mount. Used by `MenuItem::filter()->applies()` to deep-link straight
    // to a pre-filtered view (e.g. "Open Tickets", "Overdue Invoices") and
    // by anyone hand-crafting shareable URLs. The param is **kept** in
    // the URL (unlike `?search=`) so the Sidebar's filter-aware active
    // state can read it consistently across renders — stripping it
    // would only flicker the active highlight.
    const urlFilters = initialUrlParams.get('filters') ?? ''
    if (urlFilters) {
      try {
        const parsed = JSON.parse(urlFilters)
        if (parsed && typeof parsed === 'object' && !Array.isArray(parsed)) {
          setViewIsUrlDriven(true)
          setSearch('')
          setDebouncedSearch('')
          setActiveFilters(parsed as ActiveFilters)
          setPage(1)
          setPerPage(null)
          setSortBy(null)
          setSortDir('asc')
          setTrashedFilter('')
          setFiltersOpen(true)
          return
        }
      } catch {
        // Malformed JSON — fall through to the sticky-view / defaults path.
      }
    }

    // No URL-driven view state — restore sticky storage. This applies
    // uniformly across navigation types:
    //
    //   - POP (initial mount, browser back/forward, refresh) — the
    //     original sticky-views use case: "open a record, click back,
    //     find the table exactly as you left it".
    //   - PUSH (NavLink click, e.g. clicking "Invoices" in the sidebar
    //     after a filter-factory deep-link) — should also restore the
    //     user's saved view. Sticky storage is safe to restore here
    //     because URL-driven transitions (`?filters=`, `?search=`,
    //     etc.) flip `viewIsUrlDriven=true` while they are active and
    //     the sticky writer is paused for that duration. So sticky
    //     reliably contains only state the user committed via the UI.
    //   - REPLACE — internal `navigate({...}, {replace:true})` calls,
    //     same treatment as POP.
    const saved = readStickyView(resource)
    if (saved) {
      setViewIsUrlDriven(false)
      setSearch(typeof saved.search === 'string' ? saved.search : '')
      setDebouncedSearch(typeof saved.search === 'string' ? saved.search : '')
      setActiveFilters((saved.activeFilters as ActiveFilters) ?? {})
      setPage(typeof saved.page === 'number' ? saved.page : 1)
      setPerPage(typeof saved.perPage === 'number' ? saved.perPage : null)
      setSortBy(typeof saved.sortBy === 'string' ? saved.sortBy : null)
      setSortDir(saved.sortDir === 'desc' ? 'desc' : 'asc')
      setTrashedFilter(
        saved.trashedFilter === 'with' || saved.trashedFilter === 'only' ? saved.trashedFilter : '',
      )
      setFiltersOpen(saved.filtersOpen === true)
      return
    }

    setViewIsUrlDriven(false)
    setSearch('')
    setDebouncedSearch('')
    setActiveFilters({})
    setPage(1)
    setPerPage(null)
    setSortBy(null)
    setSortDir('asc')
    setTrashedFilter('')
    setFiltersOpen(false)
    // location.search is intentionally a dep: this hook runs again when
    // the user navigates inside the same resource (filter factory click
    // → ?filters= URL → re-hydrate from URL). navigationType is no
    // longer used in the body of this hook but we keep it here so a
    // cleanly-typed POP-vs-PUSH distinction is available if a future
    // edge case needs it.
  }, [resource, location.search, navigationType])
  // Debounce search
  const handleSearchChange = useCallback((value: string) => {
    markUserDriven()
    setSearch(value)
    clearTimeout(searchTimerRef.current)
    searchTimerRef.current = setTimeout(() => {
      setDebouncedSearch(value)
      setPage(1)
    }, 300)
  }, [markUserDriven])

  // Schema
  const schemaQuery = useQuery({
    queryKey: ['schema', resource],
    queryFn: () => api.get<{ data: ResourceSchema }>(`/api/resources/${resource}/schema`),
    enabled: !!resource,
  })

  const schema = schemaQuery.data?.data

  // Seed the sort state from the resource's `defaultSort()` on the
  // first schema load. `sortBy === null` means the user hasn't picked
  // a column yet, so we adopt the server-side default and render the
  // caret on the right column. Subsequent header clicks keep working.
  useEffect(() => {
    if (!schema?.defaultSort) return
    if (sortBy !== null) return
    setSortBy(schema.defaultSort)
    setSortDir(schema.defaultSortDirection ?? 'asc')
  }, [schema?.defaultSort, schema?.defaultSortDirection, sortBy])

  usePageTitle(schema?.label ?? null)
  useResourceAccent((schema as { accentColor?: string | null } | undefined)?.accentColor)
  useResourceLoaderConfig((schema as { loaderConfig?: Record<string, unknown> } | undefined)?.loaderConfig)

  // Sticky view writer — every meaningful state change rolls into
  // sessionStorage under `martis:view:{uriKey}` so the next visit to
  // this resource (e.g. via "Back" from a record's detail page)
  // restores the table exactly as the user left it. Gated on the
  // schema's `stickyView` flag so opted-out resources never write.
  const stickyEnabled = schema !== undefined && schema.stickyView !== false
  useStickyView(
    resource ?? '',
    {
      page,
      perPage,
      sortBy,
      sortDir,
      trashedFilter,
      activeFilters,
      search: debouncedSearch,
      filtersOpen,
    },
    // Pause sticky writes while the view was set from a URL deep-link
    // (`?filters=` / `?search=`) — those are transient and must not
    // overwrite the user's persisted manual view. The first user-driven
    // change (markUserDriven in any handler) flips this back on.
    stickyEnabled && !viewIsUrlDriven,
  )

  // Reset the saved view for THIS resource and bring the table back
  // to its defaults. Called from the "Reset view" toolbar button.
  const handleResetView = useCallback(() => {
    if (!resource) return
    markUserDriven()
    clearStickyView(resource)
    setSearch('')
    setDebouncedSearch('')
    setActiveFilters({})
    setPage(1)
    setPerPage(null)
    setSortBy(schema?.defaultSort ?? null)
    setSortDir(schema?.defaultSortDirection ?? 'asc')
    setTrashedFilter('')
    setFiltersOpen(false)
  }, [resource, schema?.defaultSort, schema?.defaultSortDirection, markUserDriven])

  /**
   * Clear ONLY the active filters — keeps sort, search, pagination,
   * and trashed-toggle intact. Surfaced as the "Reset filters" toolbar
   * button (Phase 5 of Task 09 — Nova-parity affordance).
   *
   * Distinct from `handleResetView`, which is the bigger hammer that
   * resets everything to the resource's defaults. The two coexist
   * because filters are the most common reset target by far and
   * users frequently want to keep their sort + page size.
   */
  const handleResetFilters = useCallback(() => {
    markUserDriven()
    setActiveFilters({})
    setPage(1)
  }, [markUserDriven])

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
    markUserDriven()
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
    markUserDriven()
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

  if (schemaQuery.isError) {
    return <ResourceErrorPage error={schemaQuery.error} />
  }

  if (!schema) {
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

  // Bulk Actions dropdown — appears in the header next to the Create button
  // only while at least one row is selected. Hidden otherwise so the header
  // stays quiet when there is nothing to act on.
  const selectionCount = selectedIds.size
  const hasBulk = indexActions.length > 0 && selectionCount > 0
  const bulkSelectedRows = rows.filter(r => selectedIds.has(r.id))
  const bulkDisabledActions = new Set<string>()
  if (hasBulk) {
    for (const action of indexActions) {
      if (action.sole && selectionCount > 1) {
        bulkDisabledActions.add(action.uriKey)
        continue
      }
      const allDisabled = bulkSelectedRows.length > 0 && bulkSelectedRows.every(row => {
        const perAction = row._actionAuthorization
        if (perAction && action.uriKey in perAction) return !perAction[action.uriKey]
        return false
      })
      if (allDisabled) bulkDisabledActions.add(action.uriKey)
    }
  }
  const bulkDropdown = hasBulk ? (
    <ActionDropdown
      actions={indexActions}
      onSelect={handleActionSelect}
      label={schema.bulkActionsMenuLabel || schema.actionsMenuLabel || tAct('bulk_actions')}
      disabledActions={bulkDisabledActions}
    />
  ) : null

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
          {/* Bulk actions — always rendered when the resource has bulk
              actions, disabled until at least one row is checked. Sits
              immediately before the Create button. */}
          {bulkDropdown}
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

      {/* Toolbar + Table + pagination share a single card surface — the
          filter row, the search/per-page/trashed row, the table, and the
          paginator all read as parts of the same card. Bulk actions live
          in the page header (next to Create), not inside the toolbar. */}
      <div className="martis-index-surface">
        {(() => {
          const hasFilters = (schema.filters?.length ?? 0) > 0
          const showTrashed = schema.softDeletes

          // The view is "dirty" when any persisted bucket differs from
          // its default. Drives the Reset View button's visibility.
          const isViewDirty =
            stickyEnabled && (
              page !== 1 ||
              perPage !== null ||
              debouncedSearch.length > 0 ||
              Object.keys(activeFilters).length > 0 ||
              trashedFilter !== '' ||
              (sortBy !== null && sortBy !== (schema.defaultSort ?? null)) ||
              (sortBy !== null && sortDir !== (schema.defaultSortDirection ?? 'asc'))
            )

          const hasActiveFilters = Object.keys(activeFilters).length > 0

          const resetFiltersButton = hasActiveFilters ? (
            <button
              type="button"
              onClick={handleResetFilters}
              className="martis-btn-ghost martis-btn-sm inline-flex items-center gap-1.5"
              data-pr-tooltip={tMsg('reset_filters_tooltip', { defaultValue: 'Clear only the active filters; keep sort, search, and pagination.' })}
              data-pr-position="top"
            >
              <XIcon size={13} weight="bold" />
              {tAct('reset_filters', { defaultValue: 'Reset filters' })}
            </button>
          ) : null

          const resetButton = isViewDirty ? (
            <button
              type="button"
              onClick={handleResetView}
              className="martis-btn-ghost martis-btn-sm inline-flex items-center gap-1.5"
              data-pr-tooltip={tMsg('reset_view_tooltip', { defaultValue: 'Reset filters, sort and pagination for this view.' })}
              data-pr-position="top"
            >
              <ArrowsClockwiseIcon size={13} weight="bold" />
              {tMsg('reset_view', { defaultValue: 'Reset view' })}
            </button>
          ) : null

          // The two buttons coexist on the toolbar: filters-only sits
          // on the left of the broader view reset.
          const combinedReset = (resetFiltersButton || resetButton) ? (
            <div className="inline-flex items-center gap-1.5">
              {resetFiltersButton}
              {resetButton}
            </div>
          ) : null

          const filterRow = hasFilters ? (
            <FilterPanel
              filters={schema.filters!}
              value={activeFilters}
              onChange={(filters) => { markUserDriven(); setActiveFilters(filters); setPage(1) }}
              rightSlot={combinedReset}
              open={filtersOpen}
              onOpenChange={(open) => { markUserDriven(); setFiltersOpen(open) }}
            />
          ) : null

          // When the resource has no filter panel, surface the Reset
          // buttons on their own row so the affordance still shows up
          // when the user has applied a sort / search / pagination
          // change worth resetting.
          const standaloneReset = !hasFilters && (isViewDirty || hasActiveFilters) ? (
            <div className="flex items-center justify-end">
              {combinedReset}
            </div>
          ) : null

          return (
            <div className="martis-index-toolbar">
              {filterRow}
              {standaloneReset}
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

                {showTrashed && (
                  <div className="flex items-center gap-2 flex-shrink-0">
                    <select
                      value={trashedFilter}
                      onChange={(e) => { markUserDriven(); setTrashedFilter(e.target.value as "" | "with" | "only"); setPage(1) }}
                      className="martis-perpage-select"
                    >
                      <option value="">{t("trashed_active")}</option>
                      <option value="with">{t("trashed_with")}</option>
                      <option value="only">{t("trashed_only")}</option>
                    </select>
                  </div>
                )}
              </div>
            </div>
          )
        })()}

      <MartisLoader loading={indexQuery.isFetching} overlay>
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
          onPageChange={(p) => { markUserDriven(); setPage(p) }}
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
