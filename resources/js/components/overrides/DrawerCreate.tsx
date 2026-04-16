import { useMemo, useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { api, ApiError, hasFileValues } from '@/lib/api'
import type { OverrideProps, FieldDefinition, PanelDefinition, TabGroupDefinition, SectionDefinition } from '@/types'
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

/** Build inline gridColumn style with responsive media handled via CSS custom properties. */
function colSpanStyle(field: { colSpan?: number; colSpanMd?: number | null; colSpanLg?: number | null }): React.CSSProperties {
  const span = resolveColSpan(field)
  return { gridColumn: `span ${span.base} / span ${span.base}` } as React.CSSProperties
}

/**
 * Built-in drawer override for the CREATE context.
 *
 * Renders a sliding drawer with the resource's create form.
 * Registered as 'martis:drawer-create' in the component registry.
 */
export function DrawerCreate(props: OverrideProps) {
  const { schema, resource, params, onCreated, onClose, addToast } = props
  const qc = useQueryClient()
  const { t: tAct } = useTranslation('actions')
  const { t: tMsg } = useTranslation('messages')

  const allFormFields = useMemo(() => schema.fieldsForCreate ?? [], [schema])

  const [values, setValues] = useState<Record<string, unknown>>({})
  const [errors, setErrors] = useState<Record<string, string>>({})

  const createMutation = useMutation({
    mutationFn: (data: Record<string, unknown>) => {
      if (hasFileValues(data)) {
        return api.upload<{ data: { id: string | number }; meta?: { message?: string } }>(
          'POST',
          `/api/resources/${resource}`,
          data,
        )
      }
      return api.post<{ data: { id: string | number }; meta?: { message?: string } }>(
        `/api/resources/${resource}`,
        data,
      )
    },
    onSuccess: (res) => {
      void qc.invalidateQueries({ queryKey: ['resources', resource] })
      setValues({})
      setErrors({})
      onCreated(res.data)
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
        addToast('error', err.message || tMsg('error_create'))
      } else {
        addToast('error', tMsg('error_create'))
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
    createMutation.mutate(values)
  }

  const title = `${tAct('create')} ${schema.singularLabel}`
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
            form="martis-drawer-create-form"
            disabled={createMutation.isPending}
            className="rounded-md px-4 py-2 text-sm font-medium text-white disabled:opacity-60"
            style={{ backgroundColor: 'var(--martis-accent)' }}
          >
            {createMutation.isPending ? tAct('saving') : `${tAct('create')} ${schema.singularLabel}`}
          </button>
        </>
      }
    >
      <form id="martis-drawer-create-form" onSubmit={handleSubmit} noValidate className="p-6 space-y-4">
        {allFormFields.map((item, idx) => {
          if (item.type === 'tab_group') {
            return <TabsInput key={idx} tabGroup={item as TabGroupDefinition} values={values} onChange={handleChange} errors={errors} resourceKey={resource} context="create" />
          }
          if (item.type === 'section') {
            return <SectionInput key={idx} section={item as SectionDefinition} values={values} onChange={handleChange} errors={errors} resourceKey={resource} context="create" />
          }
          if (item.type === 'panel') {
            return <PanelInput key={idx} panel={item as PanelDefinition} values={values} onChange={handleChange} errors={errors} resourceKey={resource} context="create" />
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
                context="create"
              />
            </div>
          )
        })}
      </form>
    </DrawerShell>
  )
}
