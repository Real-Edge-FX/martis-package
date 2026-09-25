import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, ApiError, hasFileValues } from '@/lib/api'
import type { OverrideProps, FieldDefinition, PanelDefinition, TabGroupDefinition, SectionDefinition } from '@/types'
import { FieldInput } from '@/components/fields/FieldRenderer'
import { FieldWrapper } from '@/components/fields/FieldWrapper'
import { fieldErrorProps } from '@/lib/fieldErrors'
import { PanelInput } from '@/components/fields/PanelRenderer'
import { SectionInput } from '@/components/fields/SectionRenderer'
import { TabsInput } from '@/components/fields/TabsRenderer'
import { useTranslation } from 'react-i18next'
import { DrawerShell } from './DrawerShell'
import { UnsavedChangesDialog } from '@/components/UnsavedChangesDialog'
import { NestedParentProvider } from '@/components/fields/NestedParentContext'



/**
 * Built-in drawer override for the CREATE context.
 *
 * Renders a sliding drawer with the resource's create form.
 * Registered as 'martis:drawer-create' in the component registry.
 */
export function DrawerCreate(props: OverrideProps) {
  const { schema, resource, params, record, fromResourceId, onCreated, onClose, addToast } = props
  const qc = useQueryClient()
  const { t: tAct } = useTranslation('actions')
  const { t: tMsg } = useTranslation('messages')

  const allFormFields = useMemo(() => schema.fieldsForCreate ?? [], [schema])

  // The record this drawer replicates: `fromResourceId`, or the record a host
  // passes (the create override's replicate contract before v1.38.0). Its
  // values come from the replicate endpoint, as on the create page: the
  // server checks `authorizedToReplicate()`, leaves File fields out and
  // hides the fields the user may not see for that record.
  const replicateId = fromResourceId ?? record?.id ?? null
  const replicateQuery = useQuery({
    queryKey: ['replicate', resource, replicateId],
    queryFn: () => api.get<{ data: { values?: Record<string, unknown> } }>(
      `/api/resources/${resource}/${replicateId}/replicate`,
    ),
    enabled: replicateId !== null,
    retry: false,
  })
  const initialValues = useMemo<Record<string, unknown>>(
    () => (replicateId === null ? {} : replicateQuery.data?.data?.values ?? {}),
    [replicateId, replicateQuery.data],
  )
  // A copy is ready once its values have arrived; a plain create at once.
  const ready = replicateId === null || replicateQuery.isSuccess

  const [values, setValues] = useState<Record<string, unknown>>(ready ? initialValues : {})
  const [errors, setErrors] = useState<Record<string, string>>({})
  // Bumped once a record is created, to mount the fields again for the next
  // one when the host keeps the drawer open (`redirectAfter` 'stay'): an
  // input cannot always tell the cleared form from its own last value.
  const [fieldsKey, setFieldsKey] = useState(0)

  // What the form was seeded for: the resource and the record it replicates.
  // A host can hand the open drawer another resource, or another record to
  // replicate, without remounting it; the form is seeded again for it, so
  // nothing from the previous target (values, errors, dirty baseline)
  // carries over. A fresh copy of the same record keeps the edits.
  const targetKey = `${resource}/${replicateId ?? ''}`
  const [seededKey, setSeededKey] = useState<string | null>(ready ? targetKey : null)
  const seeded = seededKey === targetKey
  // The record the next save copies: sent as `fromResourceId`, so the server
  // answers with the resource's replicated message. Cleared once the copy is
  // created, so a drawer its host keeps open creates plain records next.
  const [copyOf, setCopyOf] = useState<string | number | null>(replicateId)

  // ⭐ Camada B — track dirty state against the initial values so the
  // drawer can warn before discarding. A live ref for `values` avoids a
  // stale-closure false positive when popstate fires between setValues()
  // and React's next render.
  const initialSnapshot = useRef(JSON.stringify(initialValues))
  const valuesRef = useRef<Record<string, unknown>>(values)
  valuesRef.current = values
  const isDirty = useCallback(
    // Nothing typed yet for a target the form has not been seeded for.
    () => seeded && JSON.stringify(valuesRef.current) !== initialSnapshot.current,
    [seeded],
  )

  // Seed the form again when the host hands the drawer another target. The
  // fields render only once it is seeded, so every input mounts afresh with
  // the new target's values.
  useEffect(() => {
    if (seeded || !ready) return
    valuesRef.current = initialValues
    initialSnapshot.current = JSON.stringify(initialValues)
    setValues(initialValues)
    setErrors({})
    setCopyOf(replicateId)
    setSeededKey(targetKey)
  }, [seeded, ready, initialValues, targetKey, replicateId])
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
    mutationFn: (values: Record<string, unknown>) => {
      const data = copyOf !== null ? { ...values, fromResourceId: copyOf } : values
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
      // The next record starts from the empty form, which is therefore its
      // baseline (a drawer opened on a copy had the copy).
      initialSnapshot.current = JSON.stringify({})
      setFieldsKey((key) => key + 1)
      setCopyOf(null)
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

  // The record does not exist yet, whatever page the drawer opens over (the
  // record a Replicate copies, another record an action runs on), so no
  // relationship panel inside reads that page's record.
  const relationParent = { resource, id: null }

  return (
    <NestedParentProvider value={relationParent}>
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
              disabled={createMutation.isPending || !seeded}
              className="martis-btn-primary"
            >
              {createMutation.isPending ? tAct('saving') : `${tAct('create')} ${schema.singularLabel}`}
            </button>
          </>
        }
      >
        {replicateQuery.isError ? (
          <div role="alert" className="p-6 text-sm" style={{ color: 'var(--martis-danger)' }}>
            {replicateQuery.error instanceof ApiError && replicateQuery.error.message
              ? replicateQuery.error.message
              : tMsg('error_replicate', 'The record to replicate could not be loaded.')}
          </div>
        ) : !seeded ? (
          <div className="flex items-center justify-center p-12">
            <div
              className="h-8 w-8 animate-spin rounded-full border-2 border-solid border-current border-t-transparent"
              style={{ color: 'var(--martis-accent)' }}
            />
          </div>
        ) : (
          <form key={fieldsKey} id="martis-drawer-create-form" onSubmit={handleSubmit} noValidate className="martis-form-body martis-form-stack">
            {allFormFields.map((item, idx) => {
              if (item.type === 'tab_group') {
                const tg = item as TabGroupDefinition
                return <TabsInput key={tg.tabs.map((t) => t.title).join('|') || `tab_group-${idx}`} tabGroup={tg} values={values} onChange={handleChange} errors={errors} resourceKey={resource} context="create" />
              }
              if (item.type === 'section') {
                const sec = item as SectionDefinition
                return <SectionInput key={sec.title ?? `section-${idx}`} section={sec} values={values} onChange={handleChange} errors={errors} resourceKey={resource} context="create" />
              }
              if (item.type === 'panel') {
                const panel = item as PanelDefinition
                return <PanelInput key={panel.title ?? `panel-${idx}`} panel={panel} values={values} onChange={handleChange} errors={errors} resourceKey={resource} context="create" />
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
                      {...fieldErrorProps(errors, field.attribute)}
                      resourceKey={resource}
                      context="create"
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
    </NestedParentProvider>
  )
}
