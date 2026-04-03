import { useState } from 'react'
import { useParams, useNavigate, Link } from 'react-router-dom'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { api } from '@/lib/api'
import type { ResourceRecord, ResourceSchema } from '@/types'
import { FieldDisplay } from '@/components/fields'
import { DeleteModal } from '@/components/DeleteModal'
import { useToast } from '@/contexts/ToastContext'
import { useTranslation } from 'react-i18next'

export function ResourceDetailPage() {
  const { resource, id } = useParams<{ resource: string; id: string }>()
  const navigate = useNavigate()
  const qc = useQueryClient()
  const { addToast } = useToast()
  const [showDelete, setShowDelete] = useState(false)
  const { t: tAct } = useTranslation('actions')
  const { t: tMsg } = useTranslation('messages')

  const schemaQuery = useQuery({
    queryKey: ['schema', resource],
    queryFn: () => api.get<{ data: ResourceSchema }>(`/api/resources/${resource}/schema`),
    enabled: !!resource,
  })

  const recordQuery = useQuery({
    queryKey: ['resource', resource, id],
    queryFn: () => api.get<{ data: ResourceRecord }>(`/api/resources/${resource}/${id}`),
    enabled: !!resource && !!id,
  })

  const deleteMutation = useMutation({
    mutationFn: () => api.delete(`/api/resources/${resource}/${id}`),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ['resources', resource] })
      addToast('success', tMsg('record_deleted'))
      navigate(`/martis/resources/${resource}`)
    },
    onError: () => addToast('error', tMsg('error_delete')),
  })

  const restoreMutation = useMutation({
    mutationFn: () => api.put(`/api/resources/${resource}/${id}/restore`),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ['resource', resource, id] })
      addToast('success', tMsg('record_restored'))
    },
    onError: () => addToast('error', tMsg('error_restore')),
  })

  const schema = schemaQuery.data?.data
  const record = recordQuery.data?.data

  if (schemaQuery.isLoading || recordQuery.isLoading) {
    return <DetailSkeleton />
  }

  if (!schema || !record) {
    return (
      <div className="rounded-lg border border-red-200 bg-red-50 p-6 text-red-700 dark:border-red-800 dark:bg-red-950/20 dark:text-red-400">
        {tMsg('record_not_found')}
      </div>
    )
  }

  const detailFields = schema.fields.filter((f) => f.showOnDetail)
  const isDeleted = 'deleted_at' in record && record['deleted_at'] !== null

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-3">
          <Link
            to={`/martis/resources/${resource}`}
            className="text-sm text-blue-600 hover:underline dark:text-blue-400"
          >
            ← {schema.label}
          </Link>
          <span className="text-gray-300 dark:text-gray-600">/</span>
          <h1 className="text-2xl font-bold text-gray-900 dark:text-white">
            {schema.singularLabel} #{id}
          </h1>
          {isDeleted && (
            <span className="rounded-full bg-red-100 px-2 py-0.5 text-xs font-medium text-red-700 dark:bg-red-900/30 dark:text-red-400">
              {tMsg('archived')}
            </span>
          )}
        </div>
        <div className="flex items-center gap-2">
          {isDeleted && schema.softDeletes ? (
            <button
              type="button"
              onClick={() => restoreMutation.mutate()}
              disabled={restoreMutation.isPending}
              className="rounded-md border border-amber-300 bg-amber-50 px-3 py-1.5 text-sm font-medium text-amber-700 hover:bg-amber-100 dark:border-amber-700 dark:bg-amber-950/20 dark:text-amber-400"
            >
              {tAct('restore')}
            </button>
          ) : null}
          <Link
            to={`/martis/resources/${resource}/${id}/edit`}
            className="rounded-md border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300"
          >
            {tAct('edit')}
          </Link>
          <button
            type="button"
            onClick={() => setShowDelete(true)}
            className="rounded-md bg-red-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-red-700"
          >
            {tAct('delete')}
          </button>
        </div>
      </div>

      {/* Fields */}
      <div className="rounded-xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-950">
        <dl className="divide-y divide-gray-100 dark:divide-gray-800">
          {detailFields.map((field) => (
            <div key={field.attribute} className="grid grid-cols-3 gap-4 px-6 py-4">
              <dt className="text-sm font-medium text-gray-500 dark:text-gray-400">
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
        onConfirm={async () => { await deleteMutation.mutateAsync(); }}
        onCancel={() => setShowDelete(false)}
      />
    </div>
  )
}

function DetailSkeleton() {
  return (
    <div className="space-y-6 animate-pulse">
      <div className="h-8 w-64 rounded bg-gray-200 dark:bg-gray-800" />
      <div className="rounded-xl border border-gray-200 dark:border-gray-800">
        {Array.from({ length: 5 }).map((_, i) => (
          <div key={i} className="grid grid-cols-3 gap-4 border-b border-gray-100 px-6 py-4 dark:border-gray-800">
            <div className="h-4 w-24 rounded bg-gray-200 dark:bg-gray-700" />
            <div className="col-span-2 h-4 rounded bg-gray-200 dark:bg-gray-700" />
          </div>
        ))}
      </div>
    </div>
  )
}
