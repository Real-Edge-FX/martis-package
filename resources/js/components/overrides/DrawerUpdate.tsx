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
import { updatePayload } from '@/lib/updatePayload'
import { lockImmutableFields } from '@/lib/lockImmutableFields'
import { NestedParentProvider } from '@/components/fields/NestedParentContext'

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



/**
 * The values of the scalar fields, the part of the form the dirty check
 * compares: fields that manage their own state outside `values` (e.g. Trix,
 * tag widgets) may write back after mount without a user edit.
 */
function scalarSnapshot(fields: FieldDefinition[], values: Record<string, unknown>): string {
  const scalar: Record<string, unknown> = {}
  fields.forEach((field) => {
    scalar[field.attribute] = values[field.attribute] ?? null
  })
  return JSON.stringify(scalar)
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
  // An `immutable()` field renders read-only here: every update endpoint skips it.
  const allFormFields = useMemo(() => lockImmutableFields(schema.fieldsForUpdate ?? []), [schema])
  const scalarFields = useMemo(
    () => extractScalarFields(allFormFields as unknown as Array<Record<string, unknown>>),
    [allFormFields],
  )

  const [values, setValues] = useState<Record<string, unknown>>({})
  const [errors, setErrors] = useState<Record<string, string>>({})
  // The record the form was seeded from. A host can hand the open drawer
  // another record, or another resource's, without remounting it; the form
  // is initialized only once that record has seeded it, so nothing from the
  // previous one (values, edits, errors, dirty baseline) carries over.
  const recordKey = `${resource}/${recordId ?? ''}`
  const [seededKey, setSeededKey] = useState<string | null>(null)
  const initialized = seededKey === recordKey

  // ⭐ Camada B — snapshot of the values the record loaded with, used to
  // detect dirty state and warn before discarding edits. We keep a live
  // ref to `values` so the dirty check reads them synchronously — the
  // popstate fired by a detail→update swap arrives between setValues()
  // and the next render, and a stale-closure comparison would show a
  // spurious diff right as the drawer opens.
  const initialSnapshot = useRef<string | null>(null)
  // The values the save under way sent, and the record they belong to: its
  // success makes them the baseline. The inputs stay editable while the
  // request runs, so what is typed meanwhile still counts as unsaved when
  // the drawer stays open.
  const submittedSnapshot = useRef<{ recordKey: string; snapshot: string } | null>(null)
  const recordKeyRef = useRef(recordKey)
  recordKeyRef.current = recordKey
  const valuesRef = useRef<Record<string, unknown>>(values)
  valuesRef.current = values
  // Pending prompt holds BOTH resolvers so cancel explicitly rejects
  // the beforeClose Promise instead of leaving it dangling.
  const [dirtyPrompt, setDirtyPrompt] = useState<null | { confirm: () => void; cancel: () => void }>(null)

  // Pre-populate form when record loads, and again when the host hands the
  // drawer another record.
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
      setErrors({})
      setSeededKey(recordKey)
    }
  }, [activeRecord, scalarFields, initialized, recordKey])

  // Some fields (BelongsTo, Icon, Timezone, …) normalise their value on
  // mount via onChange, which would otherwise spuriously mark the drawer
  // dirty the moment it opens. Rebase the baseline once the mount wave
  // has settled — a separate effect (keyed only on `initialized`) so the
  // timer isn't cancelled by unrelated re-renders.
  useEffect(() => {
    if (!initialized) return
    const rebase = window.setTimeout(() => {
      initialSnapshot.current = scalarSnapshot(scalarFields, valuesRef.current)
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
    // Nothing to lose while the record the host handed over is still loading.
    if (!initialized || initialSnapshot.current === null) return false
    return scalarSnapshot(scalarFields, valuesRef.current) !== initialSnapshot.current
  }, [initialized, scalarFields])
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
      // A host can keep the drawer open after the save (`redirectAfter`
      // 'stay'): the saved values must not count as unsaved there, unless
      // the host has handed the drawer another record meanwhile.
      const submitted = submittedSnapshot.current
      if (submitted && submitted.recordKey === recordKeyRef.current) {
        initialSnapshot.current = submitted.snapshot
      }
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
    submittedSnapshot.current = { recordKey, snapshot: scalarSnapshot(scalarFields, values) }
    // Unchanged files left out, BelongsTo reduced to its id, MorphTo kept whole.
    updateMutation.mutate(updatePayload(values))
  }

  // The record seeds `values` in an effect; the fields mount only after it,
  // so every input starts from the stored value.
  const isLoading = !activeRecord || recordQuery.isLoading || !initialized
  const title = `${tAct('edit')} ${schema.singularLabel}`
  const subtitle = (params.subtitle as string) ?? schema.subtitle ?? null
  const icon = params.showIcon ? (params.icon as string) || schema.icon || null : null
  const iconColor = (params.iconColor as string) || null

  // The page behind the drawer may not name this record (an action, a lens row
  // or an index row opens it), so the relationship panels inside are told
  // which record they belong to.
  const relationParent = { resource, id: recordId ?? activeRecord?.id ?? '' }

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
        <NestedParentProvider value={relationParent}>
          <form id="martis-drawer-update-form" onSubmit={handleSubmit} noValidate className="martis-form-body martis-form-stack">
            {allFormFields.map((item, idx) => {
              if (item.type === 'tab_group') {
                const tg = item as TabGroupDefinition
                return <TabsInput key={tg.tabs.map((t) => t.title).join('|') || `tab_group-${idx}`} tabGroup={tg} values={values} onChange={handleChange} errors={errors} resourceKey={resource} recordId={recordId ?? undefined} context="update" />
              }
              if (item.type === 'section') {
                const sec = item as SectionDefinition
                return <SectionInput key={sec.title ?? `section-${idx}`} section={sec} values={values} onChange={handleChange} errors={errors} resourceKey={resource} recordId={recordId ?? undefined} context="update" />
              }
              if (item.type === 'panel') {
                const panel = item as PanelDefinition
                return <PanelInput key={panel.title ?? `panel-${idx}`} panel={panel} values={values} onChange={handleChange} errors={errors} resourceKey={resource} recordId={recordId ?? undefined} context="update" />
              }
              // A loose field is a full-width row of the form stack: spans only
              // place fields inside the Section, Panel and Tab grids.
              const field = item as FieldDefinition
              return (
                <div key={field.attribute}>
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
        </NestedParentProvider>
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
