import { useQuery } from '@tanstack/react-query'
import { api } from '@/lib/api'
import type { OverrideProps, ResourceRecord, FieldDefinition } from '@/types'
import { FieldDisplay } from '@/components/fields/FieldRenderer'
import { FieldLabelTooltip } from '@/components/fields/FieldLabelTooltip'
import { useTranslation } from 'react-i18next'
import { ArrowSquareOutIcon } from '@phosphor-icons/react'
import { DrawerShell } from './DrawerShell'
import { recordHref } from '@/lib/recordHref'

/**
 * Quick-look drawer override.
 *
 * Lightweight read-only surface that shows a curated subset of the
 * record's fields (just the leaf fields from `fieldsForPreview()`,
 * not the full Detail layout with Panels / Tabs / Sections).
 * Distinct from `DrawerDetail`:
 *
 *  - DrawerDetail   — canonical destination after the user navigates
 *                     into a record. Full layout, edit/delete CTAs.
 *  - DrawerQuick    — fly-by snapshot. Narrower drawer, no actions
 *                     beyond an "Open detail" link, intended for
 *                     row-level "open quickly" affordances.
 *
 * Registered as `'martis:drawer-quick'` in the component registry
 * (see `app.tsx`). Resources opt in via:
 *
 *     return [DrawerSlot::Quick->value => DrawerOverride::quick()];
 *
 * The shape of the OverrideProps payload is identical to the other
 * drawer overrides; what differs is the visual treatment.
 */
export function DrawerQuick(props: OverrideProps) {
  const { schema, resource, record, recordId, navigate, onClose } = props
  const { t } = useTranslation('actions')

  const recordQuery = useQuery({
    queryKey: ['resource', resource, recordId],
    queryFn: () => api.get<{ data: ResourceRecord }>(`/api/resources/${resource}/${recordId}`),
    enabled: !!recordId && !record,
  })

  const activeRecord = record ?? recordQuery.data?.data

  // Pull leaf fields from `fieldsForPreview` (or fall back to detail
  // when the resource hasn't customised it). The quick drawer is
  // intentionally flat — nested Panel / Tab / Section wrappers are
  // dropped so the surface stays scannable.
  const previewFields = ((schema.fieldsForPreview ?? schema.fieldsForDetail ?? []) as Array<FieldDefinition | { type: string }>)
    .filter((f): f is FieldDefinition => 'attribute' in f)

  return (
    <DrawerShell
      title={schema.singularLabel}
      subtitle={t('quick_look', { defaultValue: 'Quick look' })}
      onClose={onClose}
      footer={
        <button
          type="button"
          onClick={() => {
            navigate(recordHref(resource, recordId!))
            onClose()
          }}
          className="martis-btn martis-btn-primary inline-flex items-center gap-2"
        >
          <ArrowSquareOutIcon size={16} />
          {t('open_detail', { defaultValue: 'Open detail' })}
        </button>
      }
    >
      {!activeRecord ? (
        <div className="martis-text-muted py-8 text-center text-sm">
          {recordQuery.isLoading ? t('loading', { defaultValue: 'Loading…' }) : t('no_record', { defaultValue: 'No record found' })}
        </div>
      ) : (
        <dl className="grid grid-cols-1 gap-4">
          {previewFields.map((field) => (
            <div key={field.attribute} className="grid grid-cols-3 gap-3">
              <dt className="martis-text-muted text-sm">
                {field.label}
                <FieldLabelTooltip text={field.tooltip} />
              </dt>
              <dd className="col-span-2">
                <FieldDisplay
                  field={field}
                  value={activeRecord[field.attribute]}
                  resourceKey={resource}
                  context="detail"
                />
              </dd>
            </div>
          ))}
        </dl>
      )}
    </DrawerShell>
  )
}
