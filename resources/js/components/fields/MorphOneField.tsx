import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { useNavigate } from 'react-router-dom'
import { api } from '@/lib/api'
import type { ResourceRecord, FieldDefinition } from '@/types'
import type { FieldDisplayProps, FieldInputProps } from './types'
import { FieldDisplay } from '@/components/fields/FieldRenderer'
import { DeleteModal } from '@/components/DeleteModal'
import { NestedParentProvider, useNestedParent } from './NestedParentContext'
import { buildViaParams } from '@/lib/relationViaParams'
import { STANDALONE_RELATIONSHIP_TYPES } from '@/lib/relationshipFieldTypes'
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
 * Visual chrome aligned with HasOneField: bordered wrapper + header area
 * with bg-hover + padded content. Splits scalar from nested relations.
 */
export function MorphOneFieldDisplay({ field }: FieldDisplayProps) {
  return <MorphOneDetailPanel field={field} />
}

function MorphOneDetailPanel({ field }: { field: FieldDefinition }) {
  const { t: tAct } = useTranslation('actions')
  const { t: tMsg } = useTranslation('messages')
  const navigate = useNavigate()
  const qc = useQueryClient()

  const meta = field.morphOneMeta as {
    canCreate: boolean
    canUpdate: boolean
    canDelete: boolean
    hideCreateButton?: boolean
    hideEditAction?: boolean
    hideDeleteAction?: boolean
  } | undefined

  const showCreate = !!meta?.canCreate && !meta?.hideCreateButton
  const showEdit = !!meta?.canUpdate && !meta?.hideEditAction
  const showDelete = !!meta?.canDelete && !meta?.hideDeleteAction

  const relationship = field.relationship as string
  const relatedResource = field.relatedResource as string

  // Parent context: read from NestedParent when inside another relationship.
  const nested = useNestedParent()
  const pathParts = window.location.pathname.split('/')
  const resourcesIdx = pathParts.indexOf('resources')
  const parentResource = nested?.resource ?? (resourcesIdx >= 0 ? (pathParts[resourcesIdx + 1] ?? '') : '')
  const parentId = nested?.id !== undefined ? String(nested.id) : (resourcesIdx >= 0 ? (pathParts[resourcesIdx + 2] ?? '') : '')

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
  // Flatten panels/sections/tabs so nested fields render flat in the
  // card (same logic as HasOneField). Without this, Panels would render
  // as "—".
  const rawDetailFields: FieldDefinition[] = (schema as { fieldsForDetail?: FieldDefinition[] } | undefined)?.fieldsForDetail ?? []
  const flattenFields = (fields: FieldDefinition[]): FieldDefinition[] =>
    fields.flatMap((f) => {
      const kind = (f as { type?: string }).type
      if (kind === 'panel' || kind === 'section') {
        const inner = ((f as unknown as { fields?: FieldDefinition[] }).fields) ?? []
        return flattenFields(inner)
      }
      if (kind === 'tab_group') {
        const tabs = ((f as unknown as { tabs?: { fields?: FieldDefinition[] }[] }).tabs) ?? []
        return tabs.flatMap((t) => flattenFields(t.fields ?? []))
      }
      return [f]
    })
  const detailFields = flattenFields(rawDetailFields)

  const viaParams = buildViaParams({
    parentResource,
    parentId,
    relationship,
    relationshipType: 'morph-one',
  })

  if (recordQuery.isLoading) {
    return (
      <div className="py-4 text-sm" style={{ color: 'var(--martis-text-muted)' }}>
        {tMsg('loading', 'Loading…')}
      </div>
    )
  }

  return (
    <div
      className="rounded-lg"
      style={{ border: '1px solid var(--martis-border)', backgroundColor: 'var(--martis-surface)' }}
    >
      {/* Header */}
      <div
        className="flex flex-col gap-3 px-4 py-3 sm:flex-row sm:items-center sm:justify-between"
        style={{ borderBottom: '1px solid var(--martis-border)', backgroundColor: 'var(--martis-hover)' }}
      >
        <h3 className="text-sm font-semibold" style={{ color: 'var(--martis-text)' }}>
          {field.label}
        </h3>
        <div className="flex flex-wrap items-center gap-2">
          {/* Create is only rendered in the empty-state card to avoid duplication */}
          {record !== null && showEdit && viaParams !== null && (
            <button
              type="button"
              onClick={() =>
                navigate(
                  `/resources/${relatedResource}/${record.id as string | number}/edit${viaParams}`
                )
              }
              className="martis-btn-secondary"
            >
              <PencilSimpleIcon size={14} />
              {tAct('edit', 'Edit')}
            </button>
          )}
          {record !== null && showDelete && (
            <button
              type="button"
              onClick={() => setDeleteOpen(true)}
              className="martis-btn-danger"
            >
              <TrashIcon size={14} />
              {tAct('delete', 'Delete')}
            </button>
          )}
        </div>
      </div>

      <div className="p-4 space-y-3">
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
            {showCreate && viaParams !== null && (
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
          (() => {
            const scalar = detailFields.filter((f) => f.attribute !== 'id' && !STANDALONE_RELATIONSHIP_TYPES.has(f.type))
            const relations = detailFields.filter((f) => STANDALONE_RELATIONSHIP_TYPES.has(f.type))
            return (
              <>
                {scalar.length > 0 && (
                  <dl className="martis-divide" style={{ borderColor: 'var(--martis-border)' }}>
                    {scalar.map((f) => (
                      <div
                        key={f.attribute}
                        className="flex flex-col gap-1 py-3 sm:grid sm:grid-cols-3 sm:gap-4"
                      >
                        <dt
                          className="text-sm font-medium"
                          style={{ color: 'var(--martis-text-muted)', wordBreak: 'break-word' }}
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
                )}
                {relations.length > 0 && (
                  <NestedParentProvider value={{ resource: relatedResource, id: record.id as string | number }}>
                    {relations.map((f) => (
                      <FieldDisplay
                        key={f.attribute}
                        field={f}
                        value={null}
                        resourceKey={relatedResource}
                        context="detail"
                      />
                    ))}
                  </NestedParentProvider>
                )}
              </>
            )
          })()
        )}
      </div>

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
