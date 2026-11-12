import { FieldDisplay } from '@/components/fields/FieldRenderer'
import { FieldLabelTooltip } from '@/components/fields/FieldLabelTooltip'
import type { FieldDefinition, ResourceRecord } from '@/types'

interface DetailRowProps {
  field: FieldDefinition
  record: ResourceRecord
  resourceKey: string | undefined
  resourceId?: string | number
  /** Draw the field's label tooltip next to the label (the drawer does). */
  labelTooltip?: boolean
}

/**
 * One scalar row of a detail panel (`<dl class="martis-detail-panel">`),
 * shared by the detail page (main column and sidebar) and the detail drawer.
 *
 * - `Field::fullWidth()` lays the row out as one column, label above value.
 * - `Field::hideLabelOnDetail()` drops the visible label (a screen-reader
 *   label stays, so the `dd` keeps its term) and implies the one-column row.
 * - `data-field` and `data-type` give hosts a stable CSS hook per field.
 */
export function DetailRow({ field, record, resourceKey, resourceId, labelTooltip = false }: DetailRowProps) {
  const hideLabel = field.hideLabelOnDetail === true
  const classes = ['martis-detail-row']
  if (field.fullWidth === true || hideLabel) classes.push('is-full-width')
  if (hideLabel) classes.push('is-label-hidden')

  return (
    <div className={classes.join(' ')} data-field={field.attribute} data-type={field.component ?? field.type}>
      <dt className={hideLabel ? 'martis-detail-label sr-only' : 'martis-detail-label'}>
        {field.label}
        {labelTooltip && !hideLabel && <FieldLabelTooltip text={field.tooltip} />}
      </dt>
      <dd className="martis-detail-value">
        <FieldDisplay field={field} value={record[field.attribute]} resourceKey={resourceKey} resourceId={resourceId} context="detail" />
      </dd>
    </div>
  )
}
