import type { FieldDisplayProps, FieldInputProps } from './types'
import { ResourceIcon } from '@/components/ResourceIcon'
import { RelationshipTableShell } from '@/components/fields/relation/RelationshipTableShell'

/**
 * HasMany field display — renders differently based on context:
 * - On detail page (value is null): full inline DataTable with CRUD via shell
 * - On index page (value is a count number): compact count badge
 */
export function HasManyFieldDisplay({ field, value }: FieldDisplayProps) {
  if (typeof value === 'number') {
    return <HasManyFieldIndexDisplay field={field} value={value} />
  }
  return <HasManyDetailTable field={field} />
}

/**
 * Index display — compact count badge; optional color/icon via field config.
 */
export function HasManyFieldIndexDisplay({ field, value }: FieldDisplayProps) {
  const count = typeof value === 'number' ? value : 0
  const badgeColor = field.badgeColor as string | null
  const badgeIcon = field.badgeIcon as string | null

  return (
    <span
      className="martis-badge"
      style={{
        backgroundColor: badgeColor ? `${badgeColor}15` : 'var(--martis-surface)',
        color: badgeColor ?? 'var(--martis-text)',
        borderColor: badgeColor ? `${badgeColor}40` : 'var(--martis-border)',
      }}
    >
      {badgeIcon && <ResourceIcon iconName={badgeIcon} size={12} />}
      {count}
    </span>
  )
}

function HasManyDetailTable({ field }: { field: FieldDisplayProps['field'] }) {
  const meta = field.hasManyMeta as {
    perPage: number
    perPageOptions: number[]
    searchable: boolean
    canCreate: boolean
    canUpdate: boolean
    canDelete: boolean
    hideSearch?: boolean
    hideCreateButton?: boolean
    hidePerPageSelector?: boolean
    hideSoftDeleteToggle?: boolean
    hideViewAction?: boolean
    hideEditAction?: boolean
    hideDeleteAction?: boolean
    hideRestoreAction?: boolean
    hideForceDeleteAction?: boolean
  } | undefined

  const relationship = field.relationship as string
  const relatedResource = field.relatedResource as string
  const redirectAfterSave = (field.redirectAfterSave as string) ?? 'parent'

  const pathParts = window.location.pathname.split('/')
  const resourcesIdx = pathParts.indexOf('resources')
  const parentResource = resourcesIdx >= 0 ? pathParts[resourcesIdx + 1] : ''
  const parentId = resourcesIdx >= 0 ? pathParts[resourcesIdx + 2] : ''

  const viaBaseParams = `viaResource=${parentResource}&viaResourceId=${parentId}&viaRelationship=${relationship}`
  const viaParams = `?${viaBaseParams}&redirectMode=${redirectAfterSave}`

  return (
    <RelationshipTableShell
      title={field.label}
      relatedResource={relatedResource}
      showRelationIcon={field.showRelationIcon !== false}
      showRelationCount={field.showRelationCount !== false}
      collapsable={!!field.collapsable}
      collapsedByDefault={!!field.collapsedByDefault}
      queryKey={['has-many', parentResource, parentId, relationship]}
      fetchUrl={(params) =>
        `/api/resources/${parentResource}/${parentId}/has-many/${relationship}?${params.toString()}`
      }
      deleteUrl={(relatedId) =>
        `/api/resources/${parentResource}/${parentId}/has-many/${relationship}/${relatedId}`
      }
      createUrl={`/resources/${relatedResource}/create${viaParams}`}
      editUrl={(id) => `/resources/${relatedResource}/${id}/edit${viaParams}`}
      viewUrl={(id) => `/resources/${relatedResource}/${id}`}
      perPage={meta?.perPage ?? 10}
      perPageOptions={meta?.perPageOptions ?? [10, 25, 50]}
      searchable={!!meta?.searchable}
      canCreate={!!meta?.canCreate}
      canUpdate={!!meta?.canUpdate}
      canDelete={!!meta?.canDelete}
      hideSearch={!!meta?.hideSearch}
      hideCreateButton={!!meta?.hideCreateButton}
      hidePerPageSelector={!!meta?.hidePerPageSelector}
      hideSoftDeleteToggle={!!meta?.hideSoftDeleteToggle}
      hideViewAction={!!meta?.hideViewAction}
      hideEditAction={!!meta?.hideEditAction}
      hideDeleteAction={!!meta?.hideDeleteAction}
      hideRestoreAction={!!meta?.hideRestoreAction}
      hideForceDeleteAction={!!meta?.hideForceDeleteAction}
    />
  )
}

/** HasMany fields don't appear on forms. */
export function HasManyFieldInput(_props: FieldInputProps) {
  return null
}
