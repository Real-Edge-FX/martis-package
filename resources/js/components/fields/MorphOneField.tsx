import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { useNavigate } from 'react-router-dom'
import { api } from '@/lib/api'
import type { ResourceRecord, FieldDefinition } from '@/types'
import type { FieldDisplayProps, FieldInputProps } from './types'
import { FieldDisplay } from '@/components/fields/FieldRenderer'
import { DeleteModal } from '@/components/DeleteModal'
import { useTranslation } from 'react-i18next'
import { PlusIcon, PencilSimpleIcon, TrashIcon } from '@phosphor-icons/react'

/**
 * MorphOne field display.
 *
 * On the detail page:
 *   - Fetches the single related record via GET /api/resources/{parent}/{id}/morph-one/{relationship}
 *   - If no record exists: shows an empty state with a "Create" button
 *   - If a record exists: shows all detail fields in a card with "Edit" / "Delete" buttons
 *
 * Not shown on index or forms (consistent with Nova v5 behavior).
 */
export function MorphOneFieldDisplay({ field }: FieldDisplayProps) {
  return <MorphOneDetailPanel field={field} />
}

function MorphOneDetailPanel({ field }: { field: FieldDefinition }) {
  const { t: tAct } = useTranslation('actions')
  const { t: tMsg } = useTranslation('messages')
  const navigate = useNavigate()
  const qc = useQueryClient()

  const meta = field.hasOneMeta as {
    canCreate: boolean
    canUpdate: boolean
    canDelete: boolean
  } | undefined

  const relationship = field.relationship as string
  const relatedResource = field.relatedResource as string

  // Extract parent context from current URL
  const pathParts = window.location.pathname.split('/')
  const resourcesIdx = pathParts.indexOf('resources')
  const parentResource = resourcesIdx >= 0 ? pathParts[resourcesIdx + 1] : ''
  const parentId = resourcesIdx >= 0 ? pathParts[resourcesIdx + 2] : ''

  const [deleteOpen, setDeleteOpen] = useState(false)

  // Fetch related resource schema for field definitions
  const schemaQuery = useQuery({
    queryKey: ['schema', relatedResource],
    queryFn: () => api.get<{ data: { fieldsForDetail?: FieldDefinition[]; singularLabel?: string; softDeletes?: boolean } }>(`/api/resources/${relatedResource}/schema`),
    enabled: !!relatedResource,
  })

  // Fetch the single related record
  const recordQuery = useQuery({
    queryKey: ['morph-one', parentResource, parentId, relationship],
    queryFn: () =>
      api.get<{ data: ResourceRecord | null }>(
        `/api/resources/${parentResource}/${parentId}/morph-one/${relationship}`
      ),
    enabled: !!parentResource && !!parentId && !!relationship,
  })

  const deleteMutation = useMutation({
    mutationFn: () =>
      api.delete(
        `/api/resources/${parentResource}/${parentId}/morph-one/${relationship}`
      ),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ['morph-one', parentResource, parentId, relationship] })
      setDeleteOpen(false)
    },
  })

  const schema = schemaQuery.data?.data
  const record = recordQuery.data?.data ?? null
  const detailFields: FieldDefinition[] = (schema as { fieldsForDetail?: FieldDefinition[] } | undefined)?.fieldsForDetail ?? []

  const viaParams = `?viaResource=${parentResource}&viaResourceId=${parentId}&viaRelationship=${relationship}&viaRelationshipType=morph-one`

  if (recordQuery.isLoading) {
    return (
      <div className="mt-6 py-4 text-sm" style={{ color: 'var(--martis-text-muted)' }}>
        {tMsg('loading', 'Loading…')}
      </div>
    )
  }

  return (
    <div className="mt-6 space-y-3">
      {/* Section header */}
      <div className="flex items-center justify-between">
        <h3 className="text-lg font-semibold" style={{ color: 'var(--martis-text)' }}>
          {field.label}
        </h3>
        <div className="flex items-center gap-2">
          {record === null && meta?.canCreate && (
            <button
              type="button"
              onClick={() =>
                navigate(`/resources/${relatedResource}/create${viaParams}`)
              }
              className="inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-sm font-medium text-white"
              style={{ backgroundColor: 'var(--martis-accent)' }}
            >
              <PlusIcon size={14} weight="bold" />
              {tAct('create', 'Create')}
            </button>
          )}
          {record !== null && meta?.canUpdate && (
            <button
              type="button"
              onClick={() =>
                navigate(
                  `/resources/${relatedResource}/${record.id as string | number}/edit${viaParams}`
                )
              }
              className="inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-sm font-medium"
              style={{
                borderColor: 'var(--martis-border)',
                border: '1px solid var(--martis-border)',
                backgroundColor: 'var(--martis-surface)',
                color: 'var(--martis-text)',
              }}
            >
              <PencilSimpleIcon size={14} />
              {tAct('edit', 'Edit')}
            </button>
          )}
          {record !== null && meta?.canDelete && (
            <button
              type="button"
              onClick={() => setDeleteOpen(true)}
              className="inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-sm font-medium"
              style={{
                border: '1px solid var(--martis-danger)',
                backgroundColor: 'var(--martis-danger-bg)',
                color: 'var(--martis-danger-hover)',
              }}
            >
              <TrashIcon size={14} />
              {tAct('delete', 'Delete')}
            </button>
          )}
        </div>
      </div>

      {/* Content */}
      {record === null ? (
        <div
          className="rounded-xl border border-dashed py-10 text-center text-sm"
          style={{
            borderColor: 'var(--martis-border)',
            color: 'var(--martis-text-muted)',
            backgroundColor: 'var(--martis-surface)',
          }}
        >
          {tMsg('morph_one_empty', 'No related record exists yet.')}
          {meta?.canCreate && (
            <div className="mt-3">
              <button
                type="button"
                onClick={() =>
                  navigate(`/resources/${relatedResource}/create${viaParams}`)
                }
                className="inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-sm font-medium text-white"
                style={{ backgroundColor: 'var(--martis-accent)' }}
              >
                <PlusIcon size={14} weight="bold" />
                {tAct('create', 'Create')}
              </button>
            </div>
          )}
        </div>
      ) : (
        <div
          className="rounded-xl border"
          style={{
            borderColor: 'var(--martis-border)',
            backgroundColor: 'var(--martis-card)',
          }}
        >
          <dl className="divide-y" style={{ borderColor: 'var(--martis-border)' }}>
            {detailFields
              .filter((f) => f.attribute !== 'id')
              .map((f) => (
                <div
                  key={f.attribute}
                  className="grid grid-cols-3 gap-4 px-5 py-3"
                >
                  <dt
                    className="text-sm font-medium"
                    style={{ color: 'var(--martis-text-muted)' }}
                  >
                    {f.label}
                  </dt>
                  <dd className="col-span-2 text-sm" style={{ color: 'var(--martis-text)' }}>
                    <FieldDisplay
                      field={f}
                      value={record[f.attribute]}
                      resourceKey={relatedResource}
                      context="detail"
                    />
                  </dd>
                </div>
              ))}
          </dl>
        </div>
      )}

      {/* Delete confirmation modal */}
      <DeleteModal
        open={deleteOpen}
        resourceLabel={schema?.singularLabel ?? ''}
        isSoftDelete={schema?.softDeletes ?? false}
        onConfirm={async () => {
          await deleteMutation.mutateAsync()
        }}
        onCancel={() => setDeleteOpen(false)}
      />
    </div>
  )
}

/**
 * MorphOne field input — returns null (MorphOne is detail-only, not editable via forms).
 */
export function MorphOneFieldInput(_props: FieldInputProps) {
  return null
}
