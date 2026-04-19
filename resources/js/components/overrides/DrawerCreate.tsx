import { useCallback, useMemo, useRef, useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { api, ApiError, hasFileValues } from '@/lib/api'
import type { OverrideProps, FieldDefinition, PanelDefinition, TabGroupDefinition, SectionDefinition } from '@/types'
import { FieldInput } from '@/components/fields/FieldRenderer'
import { PanelInput } from '@/components/fields/PanelRenderer'
import { SectionInput } from '@/components/fields/SectionRenderer'
import { TabsInput } from '@/components/fields/TabsRenderer'
import { useTranslation } from 'react-i18next'
import { DrawerShell } from './DrawerShell'
import { UnsavedChangesDialog } from '@/components/UnsavedChangesDialog'



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
  const { schema, resource, params, record, onCreated, onClose, addToast } = props
  const qc = useQueryClient()
  const { t: tAct } = useTranslation('actions')
  const { t: tMsg } = useTranslation('messages')

  const allFormFields = useMemo(() => schema.fieldsForCreate ?? [], [schema])

  // If a record is passed (replicate flow), prefill values from it
  const initialValues = useMemo(() => {
    if (!record) return {}
    const init: Record<string, unknown> = {}
    const walk = (items: Array<Record<string, unknown>>) => {
      for (const item of items) {
        if (item.type === 'panel' || item.type === 'section') {
          const children = item.fields as Array<Record<string, unknown>> | undefined
          if (children) walk(children)
        } else if (item.type === 'tab_group') {
          const tabs = item.tabs as Array<Record<string, unknown>> | undefined
          if (tabs) for (const tab of tabs) {
            const tf = tab.fields as Array<Record<string, unknown>> | undefined
            if (tf) walk(tf)
          }
        } else {
          const attr = item.attribute as string | undefined
          if (attr && record[attr] !== undefined) init[attr] = record[attr]
        }
      }
    }
    walk(allFormFields as Array<Record<string, unknown>>)
    return init
  }, [record, allFormFields])

  const [values, setValues] = useState<Record<string, unknown>>(initialValues)
  const [errors, setErrors] = useState<Record<string, string>>({})

  // ⭐ Camada B — track dirty state against the initial values so the
  // drawer can warn before discarding. A live ref for `values` avoids a
  // stale-closure false positive when popstate fires between setValues()
  // and React's next render.
  const initialSnapshot = useRef(JSON.stringify(initialValues))
  const valuesRef = useRef<Record<string, unknown>>(values)
  valuesRef.current = values
  const isDirty = useCallback(
    () => JSON.stringify(valuesRef.current) !== initialSnapshot.current,
    [],
  )
  // `confirmUnsavedChanges` can be `true` (default config), `false`
  // (disabled), or a full UnsavedChangesConfig object.
  const confirmRaw = schema.confirmUnsavedChanges
  const confirmEnabled = confirmRaw !== false
  const confirmConfig =
    confirmRaw && typeof confirmRaw === 'object' ? confirmRaw : null

  // Pending prompt holds BOTH resolvers so cancel explicitly rejects
  // the beforeClose Promise instead of leaving it dangling (which would
  // stall the DrawerShell's async guard and break the sentinel re-arm
  // on repeated back presses).
  const [dirtyPrompt, setDirtyPrompt] = useState<null | { confirm: () => void; cancel: () => void }>(null)
  const beforeClose = useCallback(async (): Promise<boolean> => {
    if (!confirmEnabled || !isDirty()) return true
    return new Promise<boolean>((resolve) => {
      setDirtyPrompt({ confirm: () => resolve(true), cancel: () => resolve(false) })
    })
  }, [confirmEnabled, isDirty])

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
      beforeClose={beforeClose}
      footer={
        <>
          <button
            type="button"
            onClick={() => {
              void (async () => {
                const ok = await beforeClose()
                if (ok) onClose()
              })()
            }}
            className="martis-btn-secondary"
          >
            {tAct('cancel')}
          </button>
          <button
            type="submit"
            form="martis-drawer-create-form"
            disabled={createMutation.isPending}
            className="martis-btn-primary"
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
                formValues={values}
              />
            </div>
          )
        })}
      </form>

      <UnsavedChangesDialog
        open={dirtyPrompt !== null}
        config={confirmConfig}
        onCancel={() => {
          const prompt = dirtyPrompt
          setDirtyPrompt(null)
          prompt?.cancel()
        }}
        onConfirm={() => {
          const prompt = dirtyPrompt
          setDirtyPrompt(null)
          prompt?.confirm()
        }}
      />
    </DrawerShell>
  )
}
