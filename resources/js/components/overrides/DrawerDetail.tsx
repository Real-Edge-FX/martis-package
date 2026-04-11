import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { api } from '@/lib/api'
import type { OverrideProps, ResourceRecord, FieldDefinition } from '@/types'
import { FieldDisplay } from '@/components/fields'
import { DeleteModal } from '@/components/DeleteModal'
import { useTranslation } from 'react-i18next'
import { PencilSimple, Trash } from '@phosphor-icons/react'
import { DrawerShell } from './DrawerShell'



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

  const detailFields = (schema.fieldsForDetail ?? []).filter(f => f.type !== 'has_many' && f.type !== 'panel' && f.type !== 'tab_group') as FieldDefinition[]
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
              className="inline-flex items-center gap-1.5 rounded-md bg-red-600 px-3 py-2 text-sm font-medium text-white hover:bg-red-700"
            >
              <Trash size={14} />
              {tAct('delete')}
            </button>
            <button
              type="button"
              onClick={() => onEdit(recordId ? Number(recordId) || recordId : undefined)}
              className="inline-flex items-center gap-1.5 rounded-md px-3 py-2 text-sm font-medium text-white"
              style={{ backgroundColor: 'var(--martis-accent)' }}
            >
              <PencilSimple size={14} />
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
          <dl className="grid grid-cols-12 gap-0" style={{ borderColor: 'var(--martis-border)' }}>
            {detailFields.map((field) => (
              <div
                key={field.attribute}
                className="border-b px-6 py-4"
                style={{ ...colSpanStyle(field), borderColor: 'var(--martis-border)' }}
              >
                <dt
                  className="mb-1 text-xs font-medium uppercase tracking-wider"
                  style={{ color: 'var(--martis-text-muted)' }}
                >
                  {field.label}
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
