import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { NestedParentProvider, useNestedParent } from './NestedParentContext'
import { buildViaParams } from '@/lib/relationViaParams'
import { useNavigate } from 'react-router-dom'
import { api } from '@/lib/api'
import type { ResourceRecord, FieldDefinition } from '@/types'
import type { FieldDisplayProps, FieldInputProps } from './types'
import { FieldDisplay } from '@/components/fields/FieldRenderer'
import { DeleteModal } from '@/components/DeleteModal'
import { useTranslation } from 'react-i18next'
import { PlusIcon, PencilSimpleIcon, TrashIcon } from '@phosphor-icons/react'

/** ⭐ Martis differential helper — human labels for OfMany aggregate tile. */
function fnLabel(fn: string): string {
  switch (fn) {
    case 'count': return 'Total'
    case 'sum': return 'Soma'
    case 'avg': return 'Média'
    case 'min': return 'Mínimo'
    case 'max': return 'Máximo'
    default: return fn
  }
}

function formatAggregate(agg: { fn: string; column: string; value: number | null }): string {
  if (agg.value === null) return '—'
  if (agg.fn === 'count') return String(Math.round(agg.value))
  // Heuristic: columns that look like money render with 2 decimals.
  if (/amount|revenue|price|cost|total/i.test(agg.column)) {
    return new Intl.NumberFormat('pt-PT', { style: 'currency', currency: 'EUR' }).format(agg.value)
  }
  return new Intl.NumberFormat('pt-PT', { maximumFractionDigits: 2 }).format(agg.value)
}

/**
 * HasOne field display.
 *
 * On the detail page:
 *   - Fetches the single related record via GET /api/resources/{parent}/{id}/has-one/{relationship}
 *   - If no record exists: shows an empty state with a "Create" button
 *   - If a record exists: shows all detail fields in a card with "Edit" / "Delete" buttons
 *
 * Not shown on index or forms (consistent with Nova v5 behavior).
 */
export function HasOneFieldDisplay({ field }: FieldDisplayProps) {
  return <HasOneDetailPanel field={field} />
}

function HasOneDetailPanel({ field }: { field: FieldDefinition }) {
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

  // Parent context: read NestedParent when rendered inside another
  // relationship (e.g. Última fatura inside a HasOneThrough Project).
  // Fallback to the URL when we are at the top level of the detail page.
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

  // Fetch the single related record (plus Martis-diff meta: ofMany + throughBreadcrumb).
  const recordQuery = useQuery({
    queryKey: ['has-one', parentResource, parentId, relationship],
    queryFn: () =>
      api.get<{
        data: ResourceRecord | null
        meta?: {
          ofMany?: {
            totalCount?: number
            aggregate?: { fn: string; column: string; value: number | null }
          }
          throughBreadcrumb?: { enabled?: boolean; relationship?: string; text?: string | null }
        }
      }>(
        `/api/resources/${parentResource}/${parentId}/has-one/${relationship}`
      ),
    enabled: !!parentResource && !!parentId && !!relationship,
  })

  const deleteMutation = useMutation({
    mutationFn: () =>
      api.delete(
        `/api/resources/${parentResource}/${parentId}/has-one/${relationship}`
      ),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ['has-one', parentResource, parentId, relationship] })
      setDeleteOpen(false)
    },
  })

  const schema = schemaQuery.data?.data
  const record = recordQuery.data?.data ?? null
  const relMeta = recordQuery.data?.meta
  const ofMany = relMeta?.ofMany
  const breadcrumb = relMeta?.throughBreadcrumb
  // Flatten any Panel/Section/TabGroup containers at the top level of
  // fieldsForDetail — the nested HasOne card only shows flat field rows,
  // so we lift the inner fields up. Without this, the related resource's
  // Panel items render as "—" because they have no attribute/value.
  const rawDetailFields: FieldDefinition[] = (schema as { fieldsForDetail?: FieldDefinition[] } | undefined)?.fieldsForDetail ?? []
  const flattenFields = (fields: FieldDefinition[]): FieldDefinition[] =>
    fields.flatMap((f) => {
      if (f.type === 'panel' || f.type === 'section') {
        const inner = ((f as unknown as { fields?: FieldDefinition[] }).fields) ?? []
        return flattenFields(inner)
      }
      if (f.type === 'tab_group') {
        const tabs = ((f as unknown as { tabs?: { fields?: FieldDefinition[] }[] }).tabs) ?? []
        return tabs.flatMap((t) => flattenFields(t.fields ?? []))
      }
      return [f]
    })
  const detailFields: FieldDefinition[] = flattenFields(rawDetailFields)

  const viaParams = buildViaParams({
    parentResource,
    parentId,
    relationship,
    relationshipType: 'has-one',
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
      {/* Section header — same chrome as Panels for visual consistency */}
      <div
        className="flex flex-col gap-3 px-4 py-3 sm:flex-row sm:items-center sm:justify-between"
        style={{ borderBottom: '1px solid var(--martis-border)', backgroundColor: 'var(--martis-hover)' }}
      >
        <div className="flex items-baseline gap-2">
          <h3 className="text-sm font-semibold" style={{ color: 'var(--martis-text)' }}>
            {field.label}
          </h3>
          {/* ⭐ Martis differential — Latest-of-N pill on HasOne::ofMany */}
          {record !== null && typeof ofMany?.totalCount === 'number' && ofMany.totalCount > 1 && (
            <span className="martis-ofmany-pill" title={`${ofMany.totalCount} registos no total`}>
              1 de {ofMany.totalCount}
            </span>
          )}
          {/* ⭐ Through breadcrumb tooltip marker — uses MartisTooltip
           *  (via data-pr-tooltip). Default text comes from i18n; the
           *  developer can override it via ->throughBreadcrumb(true,
           *  'Explanation text') on the PHP resource. */}
          {breadcrumb?.enabled && (
            <span
              className="martis-through-hint"
              data-pr-tooltip={
                breadcrumb.text && breadcrumb.text.length > 0
                  ? breadcrumb.text
                  : tMsg('through_tooltip', { relationship: breadcrumb.relationship ?? '—' })
              }
              data-pr-position="top"
            >
              ↳ {tMsg('through_label', 'indirect')}
            </span>
          )}
        </div>
        <div className="flex flex-wrap items-center gap-2">
          {/* O bot\u00e3o Criar do header foi removido quando record === null
           *  para evitar duplica\u00e7\u00e3o com o Criar prominente dentro do
           *  empty-state card abaixo. */}
          {record !== null && meta?.canUpdate && viaParams !== null && (
            <button
              type="button"
              onClick={() =>
                navigate(
                  `/resources/${relatedResource}/${record.id as string | number}/edit${viaParams}`
                )
              }
              className="inline-flex items-center gap-1.5 rounded-md border px-3 py-1.5 text-sm font-medium transition-colors"
              style={{
                borderColor: 'var(--martis-border)',
                backgroundColor: 'var(--martis-surface)',
                color: 'var(--martis-text)',
              }}
              onMouseEnter={(e) => (e.currentTarget.style.backgroundColor = 'var(--martis-hover)')}
              onMouseLeave={(e) => (e.currentTarget.style.backgroundColor = 'var(--martis-surface)')}
            >
              <PencilSimpleIcon size={14} />
              {tAct('edit', 'Edit')}
            </button>
          )}
          {record !== null && meta?.canDelete && (
            <button
              type="button"
              onClick={() => setDeleteOpen(true)}
              className="inline-flex items-center gap-1.5 rounded-md bg-red-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-red-700"
            >
              <TrashIcon size={14} />
              {tAct('delete', 'Delete')}
            </button>
          )}
        </div>
      </div>

      {/* Content area with padding — pairs with the header above inside the
       *  same border wrapper. The inner fields card border was removed
       *  (it was redundant with the outer wrapper). */}
      <div className="p-4 space-y-3">
        {/* ⭐ Martis differential — aggregate tile (OfMany).
         *  The aggregated column name goes in the hover title rather than
         *  a sub-line, keeping the tile clean. */}
        {record !== null && ofMany?.aggregate && ofMany.aggregate.value !== null && (
          <div
            className="martis-ofmany-tile"
            title={ofMany.aggregate.column === '*' ? undefined : tMsg('ofmany_aggregate_column', { column: ofMany.aggregate.column, defaultValue: `Aggregated column: ${ofMany.aggregate.column}` })}
          >
            <span className="martis-ofmany-tile-label">{fnLabel(ofMany.aggregate.fn)}</span>
            <span className="martis-ofmany-tile-value">{formatAggregate(ofMany.aggregate)}</span>
          </div>
        )}

        {record === null ? (
          <div
            className="rounded-xl border border-dashed py-10 text-center text-sm"
            style={{
              borderColor: 'var(--martis-border)',
              color: 'var(--martis-text-muted)',
              backgroundColor: 'var(--martis-surface)',
            }}
          >
            {tMsg('has_one_empty', 'No related record exists yet.')}
            {meta?.canCreate && viaParams !== null && (
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
            // Split scalar fields (rendered in dl/dt/dd) from relationship
            // fields (HasMany, HasOne, etc) — the latter render their own
            // heading/chrome and need to span the full width. Without this
            // split they would appear with a dt label on the left and the
            // panel on the right, wasting space.
            const standaloneTypes = new Set([
              'has_many', 'has_many_through',
              'has_one', 'has_one_of_many', 'has_one_through',
              'morph_one', 'morph_one_of_many', 'morph_many',
            ])
            const scalar = detailFields.filter((f) => f.attribute !== 'id' && !standaloneTypes.has(f.type))
            const relations = detailFields.filter((f) => standaloneTypes.has(f.type))
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
                {/* Nested relationships: expose the loaded record as the
                 *  real parent via NestedParentContext. Without this the
                 *  inner HasMany/HasOne fields would read the URL path and
                 *  pick up the wrong parent (e.g. team-member instead of
                 *  the nested project). */}
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
 * HasOne field input — returns null (HasOne is detail-only, not editable via forms).
 */
export function HasOneFieldInput(_props: FieldInputProps) {
  return null
}
