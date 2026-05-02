import { useState } from "react"
import { useParams, useNavigate, Link } from "react-router-dom"
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query"
import { api } from "@/lib/api"
import type { ResourceRecord, ResourceSchema, OverrideProps, FieldDefinition, PanelDefinition, TabGroupDefinition, SectionDefinition } from "@/types"
import { FieldDisplay } from "@/components/fields/FieldRenderer"
import { PanelDisplay } from "@/components/fields/PanelRenderer"
import { TabsDisplay } from "@/components/fields/TabsRenderer"
import { SectionDisplay } from "@/components/fields/SectionRenderer"
import { DeleteModal } from "@/components/DeleteModal"
import { ActionModal, ActionDropdown, ActionDrawer } from "@/components/Actions"
import type { ActionMeta } from "@/components/Actions"
import { useToast } from "@/contexts/ToastContext"
import { useTranslation } from "react-i18next"
import { ArrowLeftIcon, PencilSimpleIcon, TrashIcon, ArrowCounterClockwiseIcon, CopyIcon, TrashSimpleIcon } from "@phosphor-icons/react"
import { ResourceIcon } from "@/components/ResourceIcon"
import { NotFoundPage } from "@/pages/NotFound"
import { ResourceErrorPage } from "@/pages/ResourceError"
import { ResourceIndexPage } from "@/pages/ResourceIndex"
import { componentRegistry } from "@/lib/componentRegistry"
import { resolveRedirect } from "@/lib/resolveRedirect"
import { MartisLoader } from "@/components/Loader"
import { usePageTitle } from "@/hooks/usePageTitle"
import { useResourceAccent } from "@/lib/useResourceAccent"

export function ResourceDetailPage() {
  const { resource, id } = useParams<{ resource: string; id: string }>()
  const navigate = useNavigate()
  const qc = useQueryClient()
  const { addToast } = useToast()
  const [showDelete, setShowDelete] = useState(false)
  const [showUpdateOverride, setShowUpdateOverride] = useState(false)
  const [showCreateOverride, setShowCreateOverride] = useState(false)
  const [showForceDelete, setShowForceDelete] = useState(false)
  const [showRestore, setShowRestore] = useState(false)
  const [activeAction, setActiveAction] = useState<ActionMeta | null>(null)
  const [actionDrawer, setActionDrawer] = useState<{ type: "create" | "detail" | "update"; resource: string; recordId?: string | number } | null>(null)
  const { t: tAct } = useTranslation("actions")
  const { t: tMsg } = useTranslation("messages")

  const schemaQuery = useQuery({
    queryKey: ["schema", resource],
    queryFn: () => api.get<{ data: ResourceSchema }>(`/api/resources/${resource}/schema`),
    enabled: !!resource,
  })

  const recordQuery = useQuery({
    queryKey: ["resource", resource, id],
    queryFn: () => api.get<{ data: ResourceRecord }>(`/api/resources/${resource}/${id}`),
    enabled: !!resource && !!id,
  })

  // Actions come from the schema payload — no separate query needed.
  const allActions = (schemaQuery.data?.data?.actions ?? []) as ActionMeta[]
  const detailActions = allActions.filter((a) => a.showOnDetail)

  const deleteMutation = useMutation({
    mutationFn: () => api.delete<{ meta?: { message?: string } }>(`/api/resources/${resource}/${id}`),
    onSuccess: (res) => {
      void qc.invalidateQueries({ queryKey: ["resources"] })
      addToast("success", res?.meta?.message ?? tMsg("record_deleted"))
      navigate(`/resources/${resource}`)
    },
    onError: () => addToast("error", tMsg("error_delete")),
  })

  const restoreMutation = useMutation({
    mutationFn: () => api.put<{ meta?: { message?: string } }>(`/api/resources/${resource}/${id}/restore`),
    onSuccess: (res) => {
      void qc.invalidateQueries({ queryKey: ["resource", resource, id] })
      void qc.invalidateQueries({ queryKey: ["resources"] })
      addToast("success", res?.meta?.message ?? tMsg("record_restored"))
    },
    onError: () => addToast("error", tMsg("error_restore")),
  })

  /** Open create form with pre-filled data — uses drawer if override exists, else navigates */
  function handleReplicate() {
    if (schema?.overrides?.create) {
      setShowCreateOverride(true)
    } else {
      navigate(`/resources/${resource}/create?fromResourceId=${id}`)
    }
  }

  const forceDeleteMutation = useMutation({
    mutationFn: () => api.delete<{ meta?: { message?: string } }>(`/api/resources/${resource}/${id}/force`),
    onSuccess: (res) => {
      void qc.invalidateQueries({ queryKey: ["resources"] })
      addToast("success", res?.meta?.message ?? tMsg("record_deleted"))
      navigate(`/resources/${resource}`)
    },
    onError: () => addToast("error", tMsg("error_delete")),
  })

  function handleActionSuccess() {
    void qc.invalidateQueries({ queryKey: ["resource", resource, id] })
    void qc.invalidateQueries({ queryKey: ["resources", resource] })
    setActiveAction(null)
  }

  const schema = schemaQuery.data?.data
  const record = recordQuery.data?.data

  const recordTitle = record && schema?.titleAttribute
    ? String(record[schema.titleAttribute] ?? '')
    : ''
  usePageTitle(schema ? `${schema.singularLabel}${recordTitle ? `: ${recordTitle}` : ''}` : null)
  useResourceAccent((schema as { accentColor?: string | null } | undefined)?.accentColor)

  if (schemaQuery.isLoading || recordQuery.isLoading) {
    return (
      <div className="flex items-center justify-center py-20">
        <MartisLoader loading size="lg" />
      </div>
    )
  }

  if (schemaQuery.isError) {
    return <ResourceErrorPage error={schemaQuery.error} />
  }

  if (recordQuery.isError) {
    return <ResourceErrorPage error={recordQuery.error} />
  }

  if (!schema) {
    return <NotFoundPage />
  }

  if (!record) {
    return <NotFoundPage />
  }

  // Check for detail override — pass full standardized OverrideProps
  if (schema.overrides?.detail) {
    const OverrideComponent = componentRegistry.resolve(schema.overrides.detail.component)
    if (OverrideComponent) {
      const C = OverrideComponent as React.ComponentType<OverrideProps>
      const overrideProps: OverrideProps = {
        schema,
        resource: resource!,
        params: schema.overrides.detail.params ?? {},
        record,
        recordId: id ?? null,
        navigate: (to: string) => navigate(to),
        onClose: () => navigate(`/resources/${resource}`),
        onCreated: (rec) => {
          void qc.invalidateQueries({ queryKey: ["resources", resource] })
          addToast("success", schema.messages?.created ?? "Record created successfully.")
          const target = resolveRedirect(schema.overrides?.detail?.redirectAfter, resource!, rec.id)
          if (target) navigate(target)
        },
        onUpdated: (rec) => {
          void qc.invalidateQueries({ queryKey: ["resource", resource, id] })
          addToast("success", schema.messages?.updated ?? "Record updated successfully.")
          const target = resolveRedirect(schema.overrides?.detail?.redirectAfter, resource!, rec.id)
          if (target) navigate(target)
        },
        onDeleted: () => {
          void qc.invalidateQueries({ queryKey: ["resources", resource] })
          addToast("success", schema.messages?.deleted ?? "Record deleted successfully.")
          navigate(`/resources/${resource}`)
        },
        onEdit: (editId) => {
          const targetId = editId ?? id
          if (!targetId) return
          // Swap the detail drawer for the update drawer in place when
          // both overrides exist. This keeps the index behind the drawer
          // and avoids navigating the URL to `/edit` (which would trigger
          // a full-page remount and lose the backdrop).
          if (schema.overrides?.update && String(targetId) === String(id)) {
            setShowUpdateOverride(true)
            return
          }
          navigate(`/resources/${resource}/${targetId}/edit`)
        },
        onView: (viewId) => navigate(`/resources/${resource}/${viewId}`),
        addToast,
      }
      // Deep-linking to `/resources/:resource/:id` with a drawer detail
       // override used to render the drawer floating over an empty page.
       // Mount the index page behind so the drawer has context, and the
       // Close button/Esc fades back into the list the user would expect.
       // When the user clicked Edit inside the detail drawer we swap to
       // the update override in place (see onEdit above).
       if (showUpdateOverride && schema.overrides?.update) {
         const UpdateComponent = componentRegistry.resolve(schema.overrides.update.component)
         if (UpdateComponent) {
           const U = UpdateComponent as React.ComponentType<OverrideProps>
           const updateProps: OverrideProps = {
             ...overrideProps,
             params: schema.overrides.update.params ?? {},
             onClose: () => setShowUpdateOverride(false),
             onUpdated: (rec) => {
               void qc.invalidateQueries({ queryKey: ["resource", resource, id] })
               addToast("success", schema.messages?.updated ?? "Record updated successfully.")
               setShowUpdateOverride(false)
               const target = resolveRedirect(schema.overrides?.update?.redirectAfter, resource!, rec.id)
               if (target) navigate(target)
             },
           }
           return (
             <>
               <ResourceIndexPage />
               <U {...updateProps} />
             </>
           )
         }
       }
       return (
         <>
           <ResourceIndexPage />
           <C {...overrideProps} />
         </>
       )
    }
  }

  /** Handle Edit button — open update override drawer if available, else navigate */
  function handleEdit() {
    if (schema!.overrides?.update) {
      setShowUpdateOverride(true)
    } else {
      navigate(`/resources/${resource}/${id}/edit`)
    }
  }

  const detailFields = schema.fieldsForDetail ?? []
  // F7-11 Part 2 — sticky right-rail panel. Resolved from
  // `Resource::detailSidebar()` and emitted by the schema endpoint.
  // When non-empty, the page lays out as a 1fr 320px grid and strips
  // the sidebar attributes from the main scalar list so they only
  // render once.
  const sidebarFields = schema.detailSidebar ?? []
  const sidebarAttrs = new Set(sidebarFields.map((f) => f.attribute))
  const hasSidebar = sidebarFields.length > 0
  // Relationship fields render as full-width panels with their own heading
  // and action buttons — they must NOT be wrapped in the scalar dl/dt/dd
  // layout, otherwise the field label appears twice (once from <dt>, once
  // from the panel's own <h3>). This covers the whole has/morph family
  // including OfMany and Through variants.
  const standaloneRelationshipTypes = new Set([
    'has_many',
    'has_many_through',
    'has_one',
    'has_one_of_many',
    'has_one_through',
    'morph_one',
    'morph_one_of_many',
    'morph_many',
    'belongs_to_many',
    'morph_to_many',
  ])
  const panelItems = detailFields.filter(f => f.type === 'panel') as PanelDefinition[]
  const tabGroupItems = detailFields.filter(f => f.type === 'tab_group') as TabGroupDefinition[]
  const sectionItems = detailFields.filter(f => f.type === 'section') as SectionDefinition[]
  const scalarFields = (detailFields.filter(f =>
    !standaloneRelationshipTypes.has(f.type) &&
    f.type !== 'panel' &&
    f.type !== 'tab_group' &&
    f.type !== 'section'
  ) as FieldDefinition[]).filter((f) => !sidebarAttrs.has(f.attribute))
  const relationshipFields = detailFields.filter(f => standaloneRelationshipTypes.has(f.type)) as FieldDefinition[]
  const isDeleted = "deleted_at" in record && record["deleted_at"] !== null
  const auth = record._authorization
  const canUpdate = auth?.authorizedToUpdate !== false
  const canDelete = auth?.authorizedToDelete !== false
  const canRestore = auth?.authorizedToRestore !== false
  const canForceDelete = auth?.authorizedToForceDelete !== false
  const canReplicate = auth?.authorizedToReplicate !== false

  return (
    <div className="space-y-6">
      {/* Breadcrumb */}
      <nav className="flex items-center gap-2 text-sm">
        <Link
          to={`/resources/${resource}`}
          className="inline-flex items-center gap-1.5 rounded-md px-2 py-1 font-medium transition-colors no-underline"
          style={{
            color: "var(--martis-primary)",
            backgroundColor: "transparent",
          }}
          onMouseEnter={(e) => (e.currentTarget.style.backgroundColor = "var(--martis-hover)")}
          onMouseLeave={(e) => (e.currentTarget.style.backgroundColor = "transparent")}
        >
          <ArrowLeftIcon size={14} weight="bold" />
          <ResourceIcon iconName={(schema.icon)} size={14} />
          {schema.label}
        </Link>
        <span className="hidden sm:inline" style={{ color: "var(--martis-text-muted)" }}>/</span>
        <span className="hidden sm:inline font-semibold truncate max-w-xs" style={{ color: "var(--martis-text)" }}>
          {record._title ? record._title : `${schema.singularLabel} #${id}`}
        </span>
        {isDeleted && (
          <span
            className="ml-2 rounded-full px-2 py-0.5 text-xs font-medium"
            style={{
              backgroundColor: "color-mix(in srgb, var(--martis-danger) 12%, transparent)",
              color: "var(--martis-danger)",
            }}
          >
            {tMsg("archived")}
          </span>
        )}
      </nav>

      {/* Header with title and actions */}
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <h1 className="text-xl font-bold sm:text-2xl" style={{ color: "var(--martis-text)" }}>
          {record._title ? record._title : `${schema.singularLabel} #${id}`}
        </h1>
        <div className="flex flex-wrap items-center gap-2">
          {/* Resource actions dropdown */}
          {detailActions.length > 0 && (
            <ActionDropdown
              actions={detailActions}
              onSelect={(action) => setActiveAction(action)}
            />
          )}
          {isDeleted && schema.softDeletes && canRestore ? (
            <button
              type="button"
              onClick={() => setShowRestore(true)}
              className="martis-btn-warning"
            >
              <ArrowCounterClockwiseIcon size={14} />
              {tAct("restore")}
            </button>
          ) : null}
          {canUpdate && (
          <button type="button" onClick={handleEdit} className="martis-btn-secondary">
            <PencilSimpleIcon size={14} />
            {tAct("edit")}
          </button>
          )}
          {!isDeleted && canReplicate && (
          <button type="button" onClick={handleReplicate} className="martis-btn-secondary">
            <CopyIcon size={14} />
            {tAct("replicate")}
          </button>
          )}
          {!isDeleted && canDelete && (
          <button type="button" onClick={() => setShowDelete(true)} className="martis-btn-danger">
            <TrashIcon size={14} />
            {tAct("delete")}
          </button>
          )}
          {isDeleted && schema.softDeletes && canForceDelete && (
          <button type="button" onClick={() => setShowForceDelete(true)} className="martis-btn-danger">
            <TrashSimpleIcon size={14} />
            {tAct("delete_permanent")}
          </button>
          )}
        </div>
      </div>

      {/* F7-11 Part 2 — when `Resource::detailSidebar()` returns fields,
          the page lays out as 1fr 320px. The right rail stays sticky
          beside the scrolling main column. When the sidebar is empty
          we keep the legacy single-column flow. */}
      <div className={hasSidebar ? 'martis-detail-grid' : undefined}>
        <div className="martis-detail-main space-y-6">
          {/* Panel and Tab layout containers */}
          {tabGroupItems.map((tg, idx) => (
            <TabsDisplay key={idx} tabGroup={tg} values={record as Record<string, unknown>} resourceKey={resource} />
          ))}
          {panelItems.map((panel, idx) => (
            <PanelDisplay key={idx} panel={panel} values={record as Record<string, unknown>} resourceKey={resource} />
          ))}
          {sectionItems.map((section, idx) => (
            <SectionDisplay key={idx} section={section} values={record as Record<string, unknown>} resourceKey={resource} />
          ))}

          {/* Details panel — spec-compliant Field grid (200px label / 1fr
           *  value, 14×0 row padding, hairline bottom border between rows)
           *  wrapped in a `.martis-detail-panel` surface with a "Details"
           *  kicker above. Mirrors the main detail spec so the scalar
           *  fields read as a named block instead of a generic card. */}
          {scalarFields.length > 0 && (
            <div>
              <div className="martis-detail-kicker">{tMsg("details")}</div>
              <dl className="martis-detail-panel">
                {scalarFields.map((field) => (
                  <div key={field.attribute} className="martis-detail-row">
                    <dt className="martis-detail-label">{field.label}</dt>
                    <dd className="martis-detail-value">
                      <FieldDisplay field={field} value={record[field.attribute]} resourceKey={resource} context="detail" />
                    </dd>
                  </div>
                ))}
              </dl>
            </div>
          )}

          {/* Relationship fields (HasMany, HasOne, variants) render standalone
           * — each is a full-width panel with its own heading. They are NOT
           * wrapped in the scalar dl/dt/dd layout above, to avoid duplicated
           * labels. */}
          {relationshipFields.map((field) => (
            <FieldDisplay key={field.attribute} field={field} value={null} resourceKey={resource} />
          ))}
        </div>

        {hasSidebar && (
          <aside className="martis-detail-sidebar">
            <div className="martis-detail-kicker">{tMsg("details")}</div>
            <dl className="martis-detail-panel is-drawer">
              {sidebarFields.map((field) => (
                <div key={field.attribute} className="martis-detail-row">
                  <dt className="martis-detail-label">{field.label}</dt>
                  <dd className="martis-detail-value">
                    <FieldDisplay field={field} value={record[field.attribute]} resourceKey={resource} context="detail" />
                  </dd>
                </div>
              ))}
            </dl>
          </aside>
        )}
      </div>

      {/* Update override overlay (drawer) — shown inline when edit button clicked */}
      {showUpdateOverride && schema.overrides?.update && (() => {
        const OverrideComponent = componentRegistry.resolve(schema.overrides.update.component)
        if (!OverrideComponent) return null
        const C = OverrideComponent as React.ComponentType<OverrideProps>
        const updateOverrideProps: OverrideProps = {
          schema,
          resource: resource!,
          params: schema.overrides.update.params ?? {},
          record,
          recordId: id ?? null,
          navigate: (to: string) => navigate(to),
          onClose: () => setShowUpdateOverride(false),
          onCreated: (_rec) => {
            void qc.invalidateQueries({ queryKey: ["resources", resource] })
            addToast("success", schema.messages?.created ?? "Record created successfully.")
          },
          onUpdated: (rec) => {
            setShowUpdateOverride(false)
            void qc.invalidateQueries({ queryKey: ["resource", resource, id] })
      void qc.invalidateQueries({ queryKey: ["resources"] })
            void qc.invalidateQueries({ queryKey: ["resources", resource] })
            addToast("success", schema.messages?.updated ?? "Record updated successfully.")
            const target = resolveRedirect(schema.overrides?.update?.redirectAfter, resource!, rec.id)
            if (target) navigate(target)
          },
          onDeleted: () => {
            void qc.invalidateQueries({ queryKey: ["resources", resource] })
            addToast("success", schema.messages?.deleted ?? "Record deleted successfully.")
            navigate(`/resources/${resource}`)
          },
          onEdit: (editId) => {
            const targetId = editId ?? id
            if (targetId) navigate(`/resources/${resource}/${targetId}/edit`)
          },
          onView: (viewId) => navigate(`/resources/${resource}/${viewId}`),
          addToast,
        }
        return <C {...updateOverrideProps} />
      })()}

      {showCreateOverride && schema.overrides?.create && (() => {
        const OverrideComponent = componentRegistry.resolve(schema.overrides.create.component)
        if (!OverrideComponent) return null
        const C = OverrideComponent as React.ComponentType<OverrideProps>
        const createOverrideProps: OverrideProps = {
          schema,
          resource: resource!,
          params: schema.overrides.create.params ?? {},
          record,
          recordId: null,
          navigate: (to: string) => navigate(to),
          onClose: () => setShowCreateOverride(false),
          onCreated: (rec) => {
            setShowCreateOverride(false)
            void qc.invalidateQueries({ queryKey: ["resources", resource] })
            addToast("success", schema.messages?.created ?? "Record created successfully.")
            const target = resolveRedirect(schema.overrides?.create?.redirectAfter, resource!, rec.id)
            if (target) navigate(target)
          },
          onUpdated: () => {},
          onDeleted: () => {},
          onEdit: (editId) => { if (editId) navigate(`/resources/${resource}/${editId}/edit`) },
          onView: (viewId) => navigate(`/resources/${resource}/${viewId}`),
          addToast,
        }
        return <C {...createOverrideProps} />
      })()}

      <DeleteModal
        open={showDelete}
        resourceLabel={schema.singularLabel}
        isSoftDelete={schema.softDeletes}
        onConfirm={async () => { await deleteMutation.mutateAsync() }}
        onCancel={() => setShowDelete(false)}
        confirmMessage={schema.softDeletes ? schema.messages?.archiveConfirm : schema.messages?.deleteConfirm}
      />
<DeleteModal
        open={showForceDelete}
        resourceLabel={schema.singularLabel}
        isSoftDelete={false}
        onConfirm={async () => { await forceDeleteMutation.mutateAsync() }}
        onCancel={() => setShowForceDelete(false)}
      />

      {showRestore && (
        <div className="martis-modal-scrim" onClick={() => setShowRestore(false)}>
          <div
            role="dialog"
            aria-modal="true"
            className="martis-modal-surface"
            onClick={(e) => e.stopPropagation()}
          >
            <div className="martis-modal-head">
              <div className="flex items-center gap-3">
                <ArrowCounterClockwiseIcon size={18} weight="bold" style={{ color: "var(--martis-warning)" }} />
                <h3 className="martis-modal-head-title">{tAct("restore")} {schema.singularLabel}</h3>
              </div>
            </div>
            <div className="martis-modal-body">{tMsg("restore_confirm")}</div>
            <div className="martis-modal-foot">
              <button type="button" onClick={() => setShowRestore(false)} className="martis-btn-secondary">
                {tAct("cancel")}
              </button>
              <button
                type="button"
                onClick={async () => { await restoreMutation.mutateAsync(); setShowRestore(false) }}
                disabled={restoreMutation.isPending}
                className="martis-btn-warning"
              >
                <ArrowCounterClockwiseIcon size={14} />
                {restoreMutation.isPending ? tAct("please_wait") : tAct("restore")}
              </button>
            </div>
          </div>
        </div>
      )}

      {/* Action execution modal */}
      <ActionModal
        resource={resource!}
        action={activeAction}
        selectedIds={id ? [id] : []}
        visible={activeAction !== null}
        onHide={() => setActiveAction(null)}
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
        />
      )}
    </div>
  )
}


