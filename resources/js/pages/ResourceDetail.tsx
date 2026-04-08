import { useState } from "react"
import { useParams, useNavigate, Link } from "react-router-dom"
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query"
import { api } from "@/lib/api"
import type { ResourceRecord, ResourceSchema, OverrideProps } from "@/types"
import { FieldDisplay } from "@/components/fields"
import { DeleteModal } from "@/components/DeleteModal"
import { ActionModal, ActionDropdown } from "@/components/Actions"
import type { ActionMeta } from "@/components/Actions"
import { useToast } from "@/contexts/ToastContext"
import { useTranslation } from "react-i18next"
import { ArrowLeft, PencilSimple, Trash, ArrowCounterClockwise, Copy, TrashSimple } from "@phosphor-icons/react"
import { ResourceIcon } from "@/components/ResourceIcon"
import { NotFoundPage } from "@/pages/NotFound"
import { componentRegistry } from "@/lib/componentRegistry"
import { resolveRedirect } from "@/lib/resolveRedirect"

export function ResourceDetailPage() {
  const { resource, id } = useParams<{ resource: string; id: string }>()
  const navigate = useNavigate()
  const qc = useQueryClient()
  const { addToast } = useToast()
  const [showDelete, setShowDelete] = useState(false)
  const [showUpdateOverride, setShowUpdateOverride] = useState(false)
  const [showForceDelete, setShowForceDelete] = useState(false)
  const [showRestore, setShowRestore] = useState(false)
  const [activeAction, setActiveAction] = useState<ActionMeta | null>(null)
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

  // Fetch actions for this resource
  const actionsQuery = useQuery({
    queryKey: ["resource-actions", resource],
    queryFn: () => api.get<{ data: { actions: ActionMeta[] } }>(`/api/resources/${resource}/actions`),
    enabled: !!resource,
  })

  const allActions = actionsQuery.data?.data?.actions ?? []
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

  /** Navigate to create form with pre-filled data from this record (Nova v5 replicate flow) */
  function handleReplicate() {
    navigate(`/resources/${resource}/create?fromResourceId=${id}`)
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

  if (schemaQuery.isLoading || recordQuery.isLoading) {
    return <DetailSkeleton />
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
          if (targetId) navigate(`/resources/${resource}/${targetId}/edit`)
        },
        onView: (viewId) => navigate(`/resources/${resource}/${viewId}`),
        addToast,
      }
      return <C {...overrideProps} />
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
  const scalarFields = detailFields.filter((f) => f.type !== 'has_many')
  const hasManyFields = detailFields.filter((f) => f.type === 'has_many')
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
          <ArrowLeft size={14} weight="bold" />
          <ResourceIcon iconName={(schema.icon)} size={14} />
          {schema.label}
        </Link>
        <span style={{ color: "var(--martis-text-muted)" }}>/</span>
        <span className="font-semibold" style={{ color: "var(--martis-text)" }}>
          {record._title ? record._title : `${schema.singularLabel} #${id}`}
        </span>
        {isDeleted && (
          <span className="ml-2 rounded-full bg-red-100 px-2 py-0.5 text-xs font-medium text-red-700 dark:bg-red-900/30 dark:text-red-400">
            {tMsg("archived")}
          </span>
        )}
      </nav>

      {/* Header with title and actions */}
      <div className="flex items-center justify-between">
        <h1 className="text-2xl font-bold" style={{ color: "var(--martis-text)" }}>
          {record._title ? record._title : `${schema.singularLabel} #${id}`}
        </h1>
        <div className="flex items-center gap-2">
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
              
              className="inline-flex items-center gap-1.5 rounded-md border border-amber-300 bg-amber-50 px-3 py-1.5 text-sm font-medium text-amber-700 hover:bg-amber-100 dark:border-amber-700 dark:bg-amber-950/20 dark:text-amber-400"
            >
              <ArrowCounterClockwise size={14} />
              {tAct("restore")}
            </button>
          ) : null}
          {canUpdate && (
          <button
            type="button"
            onClick={handleEdit}
            className="inline-flex items-center gap-1.5 rounded-md border px-3 py-1.5 text-sm font-medium transition-colors"
            style={{
              borderColor: "var(--martis-border)",
              backgroundColor: "var(--martis-surface)",
              color: "var(--martis-text)",
            }}
            onMouseEnter={(e) => (e.currentTarget.style.backgroundColor = "var(--martis-hover)")}
            onMouseLeave={(e) => (e.currentTarget.style.backgroundColor = "var(--martis-surface)")}
          >
            <PencilSimple size={14} />
            {tAct("edit")}
          </button>
          )}
          {!isDeleted && canReplicate && (
          <button
            type="button"
            onClick={handleReplicate}
            className="inline-flex items-center gap-1.5 rounded-md border px-3 py-1.5 text-sm font-medium transition-colors"
            style={{
              borderColor: "var(--martis-border)",
              backgroundColor: "var(--martis-surface)",
              color: "var(--martis-text)",
            }}
            onMouseEnter={(e) => (e.currentTarget.style.backgroundColor = "var(--martis-hover)")}
            onMouseLeave={(e) => (e.currentTarget.style.backgroundColor = "var(--martis-surface)")}
          >
            <Copy size={14} />
            {tAct("replicate")}
          </button>
          )}
          {!isDeleted && canDelete && (
          <button
            type="button"
            onClick={() => setShowDelete(true)}
            className="inline-flex items-center gap-1.5 rounded-md bg-red-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-red-700"
          >
            <Trash size={14} />
            {tAct("delete")}
          </button>
          )}
          {isDeleted && schema.softDeletes && canForceDelete && (
          <button
            type="button"
            onClick={() => setShowForceDelete(true)}
            
            className="inline-flex items-center gap-1.5 rounded-md bg-red-800 px-3 py-1.5 text-sm font-medium text-white hover:bg-red-900"
          >
            <TrashSimple size={14} />
            {tAct("delete_permanent")}
          </button>
          )}
        </div>
      </div>

      {/* Fields card */}
      <div
        className="rounded-xl border"
        style={{
          borderColor: "var(--martis-border)",
          backgroundColor: "var(--martis-card)",
        }}
      >
        <dl
          className="martis-divide"
          style={{ borderColor: "var(--martis-border)" }}
        >
          {scalarFields.map((field) => (
            <div
              key={field.attribute}
              className="grid grid-cols-3 gap-4 px-6 py-4"
              style={{ borderColor: "var(--martis-border)" }}
            >
              <dt className="text-sm font-medium" style={{ color: "var(--martis-text-muted)" }}>
                {field.label}
              </dt>
              <dd className="col-span-2 text-sm">
                <FieldDisplay field={field} value={record[field.attribute]} resourceKey={resource} context="detail" />
              </dd>
            </div>
          ))}
        </dl>
      </div>

      {/* HasMany relationship tables */}
      {hasManyFields.map((field) => (
        <FieldDisplay key={field.attribute} field={field} value={null} resourceKey={resource} />
      ))}

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
        <div style={{ position: "fixed", inset: 0, zIndex: 9990 }} className="flex items-center justify-center">
          <div className="absolute inset-0 bg-black/40" onClick={() => setShowRestore(false)} />
          <div role="dialog" className="relative w-full max-w-md rounded-xl shadow-xl" style={{ backgroundColor: "var(--martis-card)", border: "1px solid var(--martis-border)" }}>
            <div className="flex items-center justify-between border-b px-6 py-4" style={{ borderColor: "var(--martis-border)" }}>
              <div className="flex items-center gap-3">
                <div className="flex h-10 w-10 items-center justify-center rounded-full bg-amber-100 dark:bg-amber-900/30">
                  <ArrowCounterClockwise size={20} className="text-amber-600 dark:text-amber-400" />
                </div>
                <span className="text-lg font-semibold" style={{ color: "var(--martis-text)" }}>{tAct("restore")} {schema.singularLabel}</span>
              </div>
            </div>
            <div className="px-6 py-4">
              <p className="text-sm" style={{ color: "var(--martis-text-muted)" }}>{tMsg("restore_confirm")}</p>
            </div>
            <div className="flex items-center justify-end gap-3 border-t px-6 py-4" style={{ borderColor: "var(--martis-border)", backgroundColor: "var(--martis-surface)", borderRadius: "0 0 0.75rem 0.75rem" }}>
              <button type="button" onClick={() => setShowRestore(false)} className="inline-flex items-center gap-2 rounded-lg border px-4 py-2 text-sm font-medium" style={{ backgroundColor: "var(--martis-input-bg)", borderColor: "var(--martis-border)", color: "var(--martis-text)" }}>{tAct("cancel")}</button>
              <button type="button" onClick={async () => { await restoreMutation.mutateAsync(); setShowRestore(false) }} disabled={restoreMutation.isPending} className="inline-flex items-center gap-2 rounded-lg bg-amber-500 px-4 py-2 text-sm font-medium text-white hover:bg-amber-600 disabled:opacity-50">
                <ArrowCounterClockwise size={14} />
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
      />
    </div>
  )
}

function DetailSkeleton() {
  return (
    <div className="space-y-6 animate-pulse">
      <div className="h-8 w-64 rounded bg-gray-200 dark:bg-gray-800" />
      <div className="rounded-xl border" style={{ borderColor: "var(--martis-border)" }}>
        {Array.from({ length: 5 }).map((_, i) => (
          <div key={i} className="grid grid-cols-3 gap-4 border-b px-6 py-4" style={{ borderColor: "var(--martis-border)" }}>
            <div className="h-4 w-24 rounded bg-gray-200 dark:bg-gray-700" />
            <div className="col-span-2 h-4 rounded bg-gray-200 dark:bg-gray-700" />
          </div>
        ))}
      </div>
    </div>
  )
}
