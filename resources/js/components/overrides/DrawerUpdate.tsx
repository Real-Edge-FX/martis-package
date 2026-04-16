import { useMemo, useEffect, useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { api, ApiError, hasFileValues } from '@/lib/api'
import type { OverrideProps, ResourceRecord, FieldDefinition, PanelDefinition, TabGroupDefinition, SectionDefinition } from '@/types'
import { FieldInput } from '@/components/fields/FieldRenderer'
import { PanelInput } from '@/components/fields/PanelRenderer'
import { SectionInput } from '@/components/fields/SectionRenderer'
import { TabsInput } from '@/components/fields/TabsRenderer'
import { useTranslation } from 'react-i18next'
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
 * Built-in drawer override for the UPDATE context.
 *
 * Renders a sliding drawer with the resource's edit form,
 * pre-populated with the existing record values.
 * Registered as 'martis:drawer-update' in the component registry.
 */
export function DrawerUpdate(props: OverrideProps) {
  const { schema, resource, params, record, recordId, onUpdated, onClose, addToast } = props
  const qc = useQueryClient()
  const { t: tAct } = useTranslation('actions')
  const { t: tMsg } = useTranslation('messages')

  // Fetch record if not already provided in props
  const recordQuery = useQuery({
    queryKey: ['resource', resource, recordId, 'update'],
    queryFn: () => api.get<{ data: ResourceRecord }>(`/api/resources/${resource}/${recordId}?context=update`),
    enabled: !!recordId && !record,
  })

  const activeRecord = record ?? recordQuery.data?.data
  const allFormFields = useMemo(() => schema.fieldsForUpdate ?? [], [schema])
  const scalarFields = useMemo(() => allFormFields.filter(f => f.type !== 'panel' && f.type !== 'tab_group' && f.type !== 'section') as FieldDefinition[], [allFormFields])

  const [values, setValues] = useState<Record<string, unknown>>({})
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [initialized, setInitialized] = useState(false)

  // Pre-populate form when record loads
  useEffect(() => {
    if (activeRecord && !initialized) {
      const initial: Record<string, unknown> = {}
      scalarFields.forEach((field) => {
        initial[field.attribute] = activeRecord[field.attribute] ?? null
      })
      setValues(initial)
      setInitialized(true)
    }
  }, [activeRecord, scalarFields, initialized])

  const updateMutation = useMutation({
    mutationFn: (data: Record<string, unknown>) => {
      if (hasFileValues(data)) {
        return api.upload<{ data: ResourceRecord; meta?: { message?: string } }>(
          'PUT',
          `/api/resources/${resource}/${recordId}`,
          data,
        )
      }
      return api.put<{ data: ResourceRecord; meta?: { message?: string } }>(
        `/api/resources/${resource}/${recordId}`,
        data,
      )
    },
    onSuccess: (res) => {
      void qc.invalidateQueries({ queryKey: ['resources', resource] })
      void qc.invalidateQueries({ queryKey: ['resource', resource, recordId] })
      onUpdated(res.data)
    },
    onError: (err) => {
      if (err instanceof ApiError && err.errors && err.errors.length > 0) {
        const errorDisplay = schema.errorDisplay ?? 'inline'
        if (errorDisplay === 'inline') {
          setErrors(err.errorsByField())
          addToast('error', err.message || tMsg('validation_errors', 'Please fix the errors below.'))
        } else {
          for (const e of err.errors) {
            addToast('error', `${e.field}: ${e.message}`)
          }
        }
      } else if (err instanceof ApiError) {
        addToast('error', err.message || tMsg('error_update'))
      } else {
        addToast('error', tMsg('error_update'))
      }
    },
  })

  function handleChange(attribute: string, value: unknown) {
    setValues((prev) => ({ ...prev, [attribute]: value }))
    if (errors[attribute]) setErrors((prev) => ({ ...prev, [attribute]: '' }))
  }

  function handleSubmit(e: React.FormEvent) {
    e.preventDefault()
    setErrors({})
    // Filter values: skip unchanged file/image fields and extract BelongsTo IDs
    const submitValues: Record<string, unknown> = {}
    for (const [key, val] of Object.entries(values)) {
      if (val === null || val === undefined) {
        submitValues[key] = val
        continue
      }
      // Skip File objects that are still existing server values (have 'url')
      if (typeof val === 'object' && !(val instanceof File) && 'url' in (val as Record<string, unknown>)) {
        continue
      }
      // BelongsTo: extract just the ID from {id, title} objects
      if (typeof val === 'object' && !(val instanceof File) && 'id' in (val as Record<string, unknown>) && 'title' in (val as Record<string, unknown>)) {
        submitValues[key] = (val as Record<string, unknown>).id
        continue
      }
      submitValues[key] = val
    }
    updateMutation.mutate(submitValues)
  }

  const isLoading = !activeRecord || recordQuery.isLoading
  const title = `${tAct('edit')} ${schema.singularLabel}`
  const subtitle = (params.subtitle as string) ?? schema.subtitle ?? null
  const icon = params.showIcon ? (params.icon as string) || schema.icon || null : null
  const iconColor = (params.iconColor as string) || null

  return (
    <DrawerShell
      title={title}
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
            onClick={onClose}
            className="rounded-md border px-4 py-2 text-sm font-medium"
            style={{
              borderColor: 'var(--martis-border)',
              backgroundColor: 'var(--martis-input-bg)',
              color: 'var(--martis-text-muted)',
            }}
          >
            {tAct('cancel')}
          </button>
          <button
            type="submit"
            form="martis-drawer-update-form"
            disabled={updateMutation.isPending || isLoading}
            className="rounded-md px-4 py-2 text-sm font-medium text-white disabled:opacity-60"
            style={{ backgroundColor: 'var(--martis-accent)' }}
          >
            {updateMutation.isPending ? tAct('saving') : tAct('save')}
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
        <form id="martis-drawer-update-form" onSubmit={handleSubmit} noValidate className="p-6 space-y-4">
          {allFormFields.map((item, idx) => {
            if (item.type === 'tab_group') {
              return <TabsInput key={idx} tabGroup={item as TabGroupDefinition} values={values} onChange={handleChange} errors={errors} resourceKey={resource} context="update" />
            }
            if (item.type === 'section') {
              return <SectionInput key={idx} section={item as SectionDefinition} values={values} onChange={handleChange} errors={errors} resourceKey={resource} context="update" />
            }
            if (item.type === 'panel') {
              return <PanelInput key={idx} panel={item as PanelDefinition} values={values} onChange={handleChange} errors={errors} resourceKey={resource} context="update" />
            }
            const field = item as FieldDefinition
            return (
              <div key={field.attribute} style={colSpanStyle(field)}>
                <label
                  htmlFor={field.attribute}
                  className="mb-1.5 block text-sm font-medium"
                  style={{ color: 'var(--martis-text-muted)' }}
                >
                  {field.label}
                  {field.required && <span className="ml-1 text-red-500">*</span>}
                </label>
                <FieldInput
                  field={field}
                  value={values[field.attribute] ?? null}
                  onChange={(v) => handleChange(field.attribute, v)}
                  error={errors[field.attribute]}
                  resourceKey={resource}
                  recordId={recordId ?? undefined}
                  context="update"
                />
              </div>
            )
          })}
        </form>
      )}
    </DrawerShell>
  )
}
