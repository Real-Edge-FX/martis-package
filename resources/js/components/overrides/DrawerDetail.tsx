import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { api } from '@/lib/api'
import type { OverrideProps, ResourceRecord, FieldDefinition, PanelDefinition, TabGroupDefinition, SectionDefinition } from '@/types'
import { FieldDisplay } from '@/components/fields/FieldRenderer'
import { FieldLabelTooltip } from '@/components/fields/FieldLabelTooltip'
import { PanelDisplay } from '@/components/fields/PanelRenderer'
import { TabsDisplay } from '@/components/fields/TabsRenderer'
import { SectionDisplay } from '@/components/fields/SectionRenderer'
import { DeleteModal } from '@/components/DeleteModal'
import { useTranslation } from 'react-i18next'
import { PencilSimpleIcon, TrashIcon } from '@phosphor-icons/react'
import { DrawerShell } from './DrawerShell'


const STANDALONE_RELATIONSHIP_TYPES = new Set([
  'has_many',
  'has_many_through',
  'has_one',
  'has_one_of_many',
  'has_one_through',
  'morph_one',
  'morph_one_of_many',
  'morph_many',
])

/** Resolve the effective column span for a field. */
function resolveColSpan(field: { colSpan?: number; colSpanMd?: number | null; colSpanLg?: number | null }): { base: number; md?: number; lg?: number } {
  const base = field.colSpan ?? 12
  return { base, md: field.colSpanMd ?? undefined, lg: field.colSpanLg ?? undefined }
}

/** Build inline gridColumn style. */
function colSpanStyle(field: { colSpan?: number; colSpanMd?: number | null; colSpanLg?: number | null }): React.CSSProperties {
  const span = resolveColSpan(field)
  return { gridColumn: `span ${span.base} / span ${span.base}` } as React.CSSProperties
}

/**
 * Built-in drawer override for the DETAIL context.
 *
 * Renders a sliding drawer with a read-only view of the record,
 * plus Edit and Delete action buttons in the footer.
 * Registered as 'martis:drawer-detail' in the component registry.
 */
export function DrawerDetail(props: OverrideProps) {
  const { schema, resource, params, record, recordId, onEdit, onDeleted, onClose, addToast } = props
  const qc = useQueryClient()
  const { t: tAct } = useTranslation('actions')
  const { t: tMsg } = useTranslation('messages')
  const [showDelete, setShowDelete] = useState(false)

  // Fetch record if not provided in props
  const recordQuery = useQuery({
    queryKey: ['resource', resource, recordId],
    queryFn: () => api.get<{ data: ResourceRecord }>(`/api/resources/${resource}/${recordId}`),
    enabled: !!recordId && !record,
  })

  const activeRecord = record ?? recordQuery.data?.data

  const deleteMutation = useMutation({
    mutationFn: () => api.delete<{ meta?: { message?: string } }>(`/api/resources/${resource}/${recordId}`),
    onSuccess: (res) => {
      void qc.invalidateQueries({ queryKey: ['resources', resource] })
      addToast('success', res?.meta?.message ?? tMsg('record_deleted'))
      onDeleted()
    },
    onError: () => addToast('error', tMsg('error_delete')),
  })

  // Partition fieldsForDetail the same way ResourceDetail does — keeps panels,
  // sections and tab groups rendering with their own chrome (headings,
  // descriptions, tab bar) instead of being silently flattened inside the
  // drawer. Loose scalar fields still fall back to a single-grid layout at
  // the top, before the structured blocks and the relationship cards.
  const detailFields = (schema.fieldsForDetail ?? []) as FieldDefinition[]
  const kindOf = (f: FieldDefinition): string => (f as { type?: string }).type ?? ''
  const panelItems = detailFields.filter((f) => kindOf(f) === 'panel') as unknown as PanelDefinition[]
  const tabGroupItems = detailFields.filter((f) => kindOf(f) === 'tab_group') as unknown as TabGroupDefinition[]
  const sectionItems = detailFields.filter((f) => kindOf(f) === 'section') as unknown as SectionDefinition[]
  const scalarFields = detailFields.filter((f) => {
    const kind = kindOf(f)
    return (
      !STANDALONE_RELATIONSHIP_TYPES.has(f.type) &&
      kind !== 'panel' &&
      kind !== 'tab_group' &&
      kind !== 'section'
    )
  }) as FieldDefinition[]
  const relationshipFields = detailFields.filter((f) => STANDALONE_RELATIONSHIP_TYPES.has(f.type)) as FieldDefinition[]
  const isLoading = !activeRecord || recordQuery.isLoading

  const recordTitle = activeRecord?._title
    ? String(activeRecord._title)
    : `${schema.singularLabel} #${recordId}`
  const subtitle = (params.subtitle as string) ?? schema.subtitle ?? null
  const icon = params.showIcon ? (params.icon as string) || schema.icon || null : null
  const iconColor = (params.iconColor as string) || null

  return (
    <>
      <DrawerShell
        title={recordTitle}
        subtitle={subtitle}
        icon={icon}
      iconColor={iconColor}
        width={params.width as string}
        expandedWidth={params.expandedWidth as string}
        allowExpand={params.allowExpand as boolean}
        allowFullscreen={params.allowFullscreen as boolean}
        showCloseButton={params.showCloseButton as boolean}
        position={params.position as 'right' | 'left'}
        backdrop={params.backdrop as boolean}
        onClose={onClose}
        footer={
          <>
            <button
              type="button"
              onClick={() => setShowDelete(true)}
              className="martis-btn-danger inline-flex items-center gap-1.5"
            >
              <TrashIcon size={14} />
              {tAct('delete')}
            </button>
            <button
              type="button"
              onClick={() => onEdit(recordId ? Number(recordId) || recordId : undefined)}
              className="martis-btn-primary inline-flex items-center gap-1.5"
            >
              <PencilSimpleIcon size={14} />
              {tAct('edit')}
            </button>
          </>
        }
      >
        {isLoading ? (
          <div className="flex items-center justify-center p-12">
            <div
              className="h-8 w-8 animate-spin rounded-full border-2 border-current border-t-transparent"
              style={{ color: 'var(--martis-accent)' }}
            />
          </div>
        ) : (
          <div className="px-6 py-4 space-y-5">
            {/* Loose scalar fields — rendered as a bordered fields card so
             *  they read visually the same as an explicit Section. */}
            {scalarFields.length > 0 && (
              <div
                className="rounded-lg"
                style={{
                  border: '1px solid var(--martis-border)',
                  backgroundColor: 'var(--martis-surface)',
                }}
              >
                <dl className="grid grid-cols-12 gap-0">
                  {scalarFields.map((field) => (
                    <div
                      key={field.attribute}
                      className="border-b px-4 py-3 last:border-b-0"
                      style={{ ...colSpanStyle(field), borderColor: 'var(--martis-border)' }}
                    >
                      <dt
                        className="mb-1 text-xs font-medium uppercase tracking-wider"
                        style={{ color: 'var(--martis-text-muted)' }}
                      >
                        {field.label}
                        <FieldLabelTooltip text={field.tooltip} />
                      </dt>
                      <dd className="text-sm" style={{ color: 'var(--martis-text)' }}>
                        <FieldDisplay
                          field={field}
                          value={activeRecord![field.attribute]}
                          resourceKey={resource}
                          context="detail"
                        />
                      </dd>
                    </div>
                  ))}
                </dl>
              </div>
            )}

            {/* Structured layout containers (tabs / panels / sections) —
             *  each keeps its own heading so the drawer mirrors the full
             *  detail page instead of flattening the declarative tree. */}
            {tabGroupItems.map((tg, idx) => (
              <TabsDisplay key={`tg-${idx}`} tabGroup={tg} values={activeRecord as Record<string, unknown>} resourceKey={resource} />
            ))}
            {panelItems.map((panel, idx) => (
              <PanelDisplay key={`panel-${idx}`} panel={panel} values={activeRecord as Record<string, unknown>} resourceKey={resource} />
            ))}
            {sectionItems.map((section, idx) => (
              <SectionDisplay key={`section-${idx}`} section={section} values={activeRecord as Record<string, unknown>} resourceKey={resource} />
            ))}

            {/* Relationship panels — each relationship field renders its own
             *  bordered card (see HasMany/MorphMany/HasOne/MorphOne). The
             *  outer space-y-5 provides clear vertical gap between them. */}
            {relationshipFields.length > 0 && (
              <div className="space-y-5">
                {relationshipFields.map((field) => (
                  <FieldDisplay
                    key={field.attribute}
                    field={field}
                    value={null}
                    resourceKey={resource}
                    context="detail"
                  />
                ))}
              </div>
            )}
          </div>
        )}
      </DrawerShell>

      <DeleteModal
        open={showDelete}
        resourceLabel={schema.singularLabel}
        isSoftDelete={schema.softDeletes}
        onConfirm={async () => { await deleteMutation.mutateAsync() }}
        onCancel={() => setShowDelete(false)}
        confirmMessage={schema.softDeletes ? schema.messages?.archiveConfirm : schema.messages?.deleteConfirm}
      />
    </>
  )
}
