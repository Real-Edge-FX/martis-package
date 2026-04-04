import { useState } from "react"
import { useParams, useNavigate, Link } from "react-router-dom"
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query"
import { api } from "@/lib/api"
import type { ResourceRecord, ResourceSchema } from "@/types"
import { FieldDisplay } from "@/components/fields"
import { DeleteModal } from "@/components/DeleteModal"
import { useToast } from "@/contexts/ToastContext"
import { useTranslation } from "react-i18next"
import { ArrowLeft, PencilSimple, Trash, ArrowCounterClockwise } from "@phosphor-icons/react"
import { ResourceIcon } from "@/components/ResourceIcon"

export function ResourceDetailPage() {
  const { resource, id } = useParams<{ resource: string; id: string }>()
  const navigate = useNavigate()
  const qc = useQueryClient()
  const { addToast } = useToast()
  const [showDelete, setShowDelete] = useState(false)
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
      addToast("success", res?.meta?.message ?? tMsg("record_restored"))
    },
    onError: () => addToast("error", tMsg("error_restore")),
  })

  const schema = schemaQuery.data?.data
  const record = recordQuery.data?.data

  if (schemaQuery.isLoading || recordQuery.isLoading) {
    return <DetailSkeleton />
  }

  if (!schema || !record) {
    return (
      <div className="rounded-lg border border-red-200 bg-red-50 p-6 text-red-700 dark:border-red-800 dark:bg-red-950/20 dark:text-red-400">
        {tMsg("record_not_found")}
      </div>
    )
  }

  const detailFields = schema.fields.filter((f) => f.showOnDetail)
  const isDeleted = "deleted_at" in record && record["deleted_at"] !== null

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
          <ResourceIcon iconName={((schema as unknown as { icon?: string }).icon)} size={14} />
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
          {isDeleted && schema.softDeletes ? (
            <button
              type="button"
              onClick={() => restoreMutation.mutate()}
              disabled={restoreMutation.isPending}
              className="inline-flex items-center gap-1.5 rounded-md border border-amber-300 bg-amber-50 px-3 py-1.5 text-sm font-medium text-amber-700 hover:bg-amber-100 dark:border-amber-700 dark:bg-amber-950/20 dark:text-amber-400"
            >
              <ArrowCounterClockwise size={14} />
              {tAct("restore")}
            </button>
          ) : null}
          <Link
            to={`/resources/${resource}/${id}/edit`}
            className="inline-flex items-center gap-1.5 rounded-md border px-3 py-1.5 text-sm font-medium no-underline transition-colors"
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
          </Link>
          <button
            type="button"
            onClick={() => setShowDelete(true)}
            className="inline-flex items-center gap-1.5 rounded-md bg-red-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-red-700"
          >
            <Trash size={14} />
            {tAct("delete")}
          </button>
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
          {detailFields.map((field) => (
            <div
              key={field.attribute}
              className="grid grid-cols-3 gap-4 px-6 py-4"
              style={{ borderColor: "var(--martis-border)" }}
            >
              <dt className="text-sm font-medium" style={{ color: "var(--martis-text-muted)" }}>
                {field.label}
              </dt>
              <dd className="col-span-2 text-sm">
                <FieldDisplay field={field} value={record[field.attribute]} resourceKey={resource} />
              </dd>
            </div>
          ))}
        </dl>
      </div>

      <DeleteModal
        open={showDelete}
        resourceLabel={schema.singularLabel}
        isSoftDelete={schema.softDeletes}
        onConfirm={async () => { await deleteMutation.mutateAsync() }}
        onCancel={() => setShowDelete(false)}
        confirmMessage={schema.softDeletes ? schema.messages?.archiveConfirm : schema.messages?.deleteConfirm}
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
