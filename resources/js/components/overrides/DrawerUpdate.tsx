import { useCallback, useMemo, useEffect, useRef, useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { api, ApiError, hasFileValues } from '@/lib/api'
import type { OverrideProps, ResourceRecord, FieldDefinition, PanelDefinition, TabGroupDefinition, SectionDefinition } from '@/types'
import { FieldInput } from '@/components/fields/FieldRenderer'
import { FieldWrapper } from '@/components/fields/FieldWrapper'
import { PanelInput } from '@/components/fields/PanelRenderer'
import { SectionInput } from '@/components/fields/SectionRenderer'
import { TabsInput } from '@/components/fields/TabsRenderer'
import { useTranslation } from 'react-i18next'
import { DrawerShell } from './DrawerShell'
import { UnsavedChangesDialog } from '@/components/UnsavedChangesDialog'

/** Recursively extract scalar fields from layout containers (Panel, Section, TabGroup) */
function extractScalarFields(items: Array<Record<string, unknown>>): FieldDefinition[] {
  const result: FieldDefinition[] = []
  for (const item of items) {
    if (item.type === 'panel' || item.type === 'section') {
      const children = (item as Record<string, unknown>).fields as Array<Record<string, unknown>> | undefined
      if (children) result.push(...extractScalarFields(children))
    } else if (item.type === 'tab_group') {
      const tabs = (item as Record<string, unknown>).tabs as Array<Record<string, unknown>> | undefined
      if (tabs) {
        for (const tab of tabs) {
          const tabFields = tab.fields as Array<Record<string, unknown>> | undefined
          if (tabFields) result.push(...extractScalarFields(tabFields))
        }
      }
    } else {
      result.push(item as unknown as FieldDefinition)
    }
  }
  return result
}



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
  const scalarFields = useMemo(
    () => extractScalarFields(allFormFields as unknown as Array<Record<string, unknown>>),
    [allFormFields],
  )

  const [values, setValues] = useState<Record<string, unknown>>({})
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [initialized, setInitialized] = useState(false)

  // ⭐ Camada B — snapshot of the values the record loaded with, used to
  // detect dirty state and warn before discarding edits. We keep a live
  // ref to `values` so the dirty check reads them synchronously — the
  // popstate fired by a detail→update swap arrives between setValues()
  // and the next render, and a stale-closure comparison would show a
  // spurious diff right as the drawer opens.
  const initialSnapshot = useRef<string | null>(null)
  const valuesRef = useRef<Record<string, unknown>>(values)
  valuesRef.current = values
  // Pending prompt holds BOTH resolvers so cancel explicitly rejects
  // the beforeClose Promise instead of leaving it dangling.
  const [dirtyPrompt, setDirtyPrompt] = useState<null | { confirm: () => void; cancel: () => void }>(null)

  // Pre-populate form when record loads
  useEffect(() => {
    if (activeRecord && !initialized) {
      const initial: Record<string, unknown> = {}
      scalarFields.forEach((field) => {
        initial[field.attribute] = activeRecord[field.attribute] ?? null
      })
      // Sync the ref alongside the snapshot so a dirty-check running
      // before the next React commit still sees matching values and
      // snapshot (i.e. not dirty).
      valuesRef.current = initial
      initialSnapshot.current = JSON.stringify(initial)
      setValues(initial)
      setInitialized(true)
    }
  }, [activeRecord, scalarFields, initialized])

  // Some fields (BelongsTo, Icon, Timezone, …) normalise their value on
  // mount via onChange, which would otherwise spuriously mark the drawer
  // dirty the moment it opens. Rebase the baseline once the mount wave
  // has settled — a separate effect (keyed only on `initialized`) so the
  // timer isn't cancelled by unrelated re-renders.
  useEffect(() => {
    if (!initialized) return
    const rebase = window.setTimeout(() => {
      const settled: Record<string, unknown> = {}
      scalarFields.forEach((field) => {
        settled[field.attribute] = valuesRef.current[field.attribute] ?? null
      })
      initialSnapshot.current = JSON.stringify(settled)
    }, 250)
    return () => window.clearTimeout(rebase)
  }, [initialized, scalarFields])

  // `confirmUnsavedChanges` can be `true` (default), `false` (disabled),
  // or a full UnsavedChangesConfig object returned from PHP.
  const confirmRaw = schema.confirmUnsavedChanges
  const confirmEnabled = confirmRaw !== false
  const confirmConfig =
    confirmRaw && typeof confirmRaw === 'object' ? confirmRaw : null

  const isDirty = useCallback(() => {
    if (initialSnapshot.current === null) return false
    // Compare only the scalar fields captured in the baseline — fields
    // that manage their own state outside `values` (e.g. Trix, tag
    // widgets) may write back after mount without representing a user
    // edit, and would otherwise flip the drawer into a false-dirty state.
    const current: Record<string, unknown> = {}
    scalarFields.forEach((field) => {
      current[field.attribute] = valuesRef.current[field.attribute] ?? null
    })
    return JSON.stringify(current) !== initialSnapshot.current
  }, [scalarFields])
  const beforeClose = useCallback(async (): Promise<boolean> => {
    if (!confirmEnabled || !isDirty()) return true
    return new Promise<boolean>((resolve) => {
      setDirtyPrompt({ confirm: () => resolve(true), cancel: () => resolve(false) })
    })
  }, [confirmEnabled, isDirty])

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
            form="martis-drawer-update-form"
            disabled={updateMutation.isPending || isLoading}
            className="martis-btn-primary"
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
              return <TabsInput key={idx} tabGroup={item as TabGroupDefinition} values={values} onChange={handleChange} errors={errors} resourceKey={resource} recordId={recordId ?? undefined} context="update" />
            }
            if (item.type === 'section') {
              return <SectionInput key={idx} section={item as SectionDefinition} values={values} onChange={handleChange} errors={errors} resourceKey={resource} recordId={recordId ?? undefined} context="update" />
            }
            if (item.type === 'panel') {
              return <PanelInput key={idx} panel={item as PanelDefinition} values={values} onChange={handleChange} errors={errors} resourceKey={resource} recordId={recordId ?? undefined} context="update" />
            }
            const field = item as FieldDefinition
            return (
              <div key={field.attribute} style={colSpanStyle(field)}>
                <FieldWrapper
                  htmlFor={field.attribute}
                  label={field.label}
                  required={field.required}
                  tooltip={field.tooltip}
                  help={field.helpText}
                >
                  <FieldInput
                    field={field}
                    value={values[field.attribute] ?? null}
                    onChange={(v) => handleChange(field.attribute, v)}
                    error={errors[field.attribute]}
                    resourceKey={resource}
                    recordId={recordId ?? undefined}
                    context="update"
                    formValues={values}
                  />
                </FieldWrapper>
              </div>
            )
          })}
        </form>
      )}

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
