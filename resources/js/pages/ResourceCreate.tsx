import { useEffect, useMemo, useRef, useState } from 'react'
import { useParams, useNavigate, Link, useSearchParams } from 'react-router-dom'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { api, ApiError, hasFileValues } from '@/lib/api'
import type { ResourceSchema, OverrideProps, FieldDefinition, PanelDefinition, TabGroupDefinition, SectionDefinition } from '@/types'
import { FieldInput } from '@/components/fields/FieldRenderer'
import { PanelInput } from '@/components/fields/PanelRenderer'
import { SectionInput } from '@/components/fields/SectionRenderer'
import { TabsInput } from '@/components/fields/TabsRenderer'
import { FieldWrapper } from '@/components/fields/FieldWrapper'
import { useToast } from '@/contexts/ToastContext'
import { useTranslation } from 'react-i18next'
import { ArrowLeftIcon } from '@phosphor-icons/react'
import { ResourceIcon } from '@/components/ResourceIcon'
import { componentRegistry } from '@/lib/componentRegistry'
import { resolveRedirect } from '@/lib/resolveRedirect'
import { useUnsavedChangesGuard } from '@/lib/useUnsavedChangesGuard'
import { usePageTitle } from '@/hooks/usePageTitle'

export function ResourceCreatePage() {
  const { resource } = useParams<{ resource: string }>()
  const navigate = useNavigate()
  const qc = useQueryClient()
  const { addToast } = useToast()
  const [searchParams] = useSearchParams()
  const viaResource = searchParams.get('viaResource')
  const viaResourceId = searchParams.get('viaResourceId')
  const viaRelationship = searchParams.get('viaRelationship')
  const viaRelationshipType = searchParams.get('viaRelationshipType') ?? 'has-many'
  const isViaRelation = !!(viaResource && viaResourceId && viaRelationship)
  const redirectMode = searchParams.get('redirectMode') ?? 'parent'
  const fromResourceId = searchParams.get('fromResourceId')
  const isReplicate = !!fromResourceId
  const { t: tAct } = useTranslation('actions')
  const { t: tMsg } = useTranslation('messages')

  const schemaQuery = useQuery({
    queryKey: ['schema', resource],
    queryFn: () => api.get<{ data: ResourceSchema }>(`/api/resources/${resource}/schema`),
    enabled: !!resource,
  })

  // Fetch pre-fill data when replicating
  const replicateQuery = useQuery({
    queryKey: ['replicate', resource, fromResourceId],
    queryFn: () => api.get<{ data: { values: Record<string, unknown>; fromResourceId: string | number } }>(
      `/api/resources/${resource}/${fromResourceId}/replicate`
    ),
    enabled: !!resource && isReplicate,
  })

  const schema = schemaQuery.data?.data
  const { t: tNav } = useTranslation('navigation')
  usePageTitle(schema ? `${tNav('create', { defaultValue: 'Create' })} ${schema.singularLabel}` : null)
  const rawFormFields = (schema?.fieldsForCreate ?? [])

  // Marca a FK do pai como readonly quando criamos via rela\u00e7\u00e3o
  // aninhada: o utilizador n\u00e3o deve poder mudar o pai —
  // s\u00f3 ver o seu nome. Deep-walk para apanhar o campo mesmo
  // dentro de Panel/Section/TabGroup.
  const allFormFields = useMemo(() => {
    if (!isViaRelation) return rawFormFields
    const walk = (fields: unknown[]): unknown[] =>
      fields.map((item) => {
        const f = item as Record<string, unknown>
        if (f.type === 'panel' || f.type === 'section') {
          return { ...f, fields: walk((f.fields as unknown[]) ?? []) }
        }
        if (f.type === 'tab_group') {
          const tabs = ((f.tabs as { fields?: unknown[] }[]) ?? []).map((t) => ({
            ...t,
            fields: walk(t.fields ?? []),
          }))
          return { ...f, tabs }
        }
        if (f.type === 'belongs_to' && f.relatedResource === viaResource) {
          return { ...f, readonly: true }
        }
        return f
      })
    return walk(rawFormFields)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [rawFormFields, isViaRelation, viaResource])

  const [values, setValues] = useState<Record<string, unknown>>({})
  const [replicateApplied, setReplicateApplied] = useState(false)
  const [errors, setErrors] = useState<Record<string, string>>({})
  const baselineRef = useRef<string | null>(null)

  // Pre-fill form with replicated values when data loads
  useEffect(() => {
    if (isReplicate && replicateQuery.data?.data?.values && !replicateApplied) {
      setValues(replicateQuery.data.data.values)
      setReplicateApplied(true)
    }
  }, [isReplicate, replicateQuery.data, replicateApplied])

  // Pre-fill the FK when creating via a relationship (viaResource points
  // to the parent). Finds a BelongsTo field whose relatedResource matches
  // viaResource and fills it with the parent ID. The nested endpoint
  // already injects the FK server-side, but without the pre-fill the
  // user would see an empty field and might pick the wrong parent.
  const [viaFkApplied, setViaFkApplied] = useState(false)
  useEffect(() => {
    if (!isViaRelation || viaFkApplied || !schema) return
    const findFk = (fields: unknown[]): string | null => {
      for (const f of fields as Record<string, unknown>[]) {
        if (f.type === 'panel' || f.type === 'section') {
          const inner = (f.fields as unknown[]) ?? []
          const hit = findFk(inner)
          if (hit) return hit
        } else if (f.type === 'tab_group') {
          const tabs = (f.tabs as { fields?: unknown[] }[]) ?? []
          for (const t of tabs) {
            const hit = findFk(t.fields ?? [])
            if (hit) return hit
          }
        } else if (f.type === 'belongs_to' && f.relatedResource === viaResource) {
          return f.attribute as string
        }
      }
      return null
    }
    const fkAttr = findFk(allFormFields)
    if (!fkAttr) {
      setViaFkApplied(true)
      return
    }
    let cancelled = false
    // Busca o _title do pai para mostrar o nome em vez de "#id" no dropdown.
    api
      .get<{ data: { id: string | number; _title?: string } }>(
        `/api/resources/${viaResource}/${viaResourceId}`,
      )
      .then((res) => {
        if (cancelled) return
        const title = res.data?._title ?? ''
        setValues((prev) => ({
          ...prev,
          [fkAttr]: { id: viaResourceId, title },
        }))
        setViaFkApplied(true)
      })
      .catch(() => {
        if (cancelled) return
        setValues((prev) => ({
          ...prev,
          [fkAttr]: { id: viaResourceId, title: '' },
        }))
        setViaFkApplied(true)
      })
    return () => { cancelled = true }
  }, [isViaRelation, viaFkApplied, schema, allFormFields, viaResource, viaResourceId])

  // Capture a baseline once the form has its real starting values
  // (either an empty object for a plain create, a replicated snapshot,
  // or — when launched via a relationship — the auto-filled parent FK).
  // The dirty guard compares against this baseline; capturing too early
  // means the pre-fill itself counts as "dirty", triggering the unsaved
  // dialog on a pristine form the user never touched.
  const initialSnapshot = useMemo(() => {
    if (!schema) return null
    if (isReplicate && !replicateApplied) return null
    if (isViaRelation && !viaFkApplied) return null
    if (baselineRef.current === null) {
      baselineRef.current = JSON.stringify(values)
    }
    return baselineRef.current
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [schema, isReplicate, replicateApplied, isViaRelation, viaFkApplied])

  const { dialog: unsavedGuardDialog, markSaved } = useUnsavedChangesGuard({
    values,
    initialSnapshot,
    schema,
  })

  const createMutation = useMutation({
    mutationFn: (data: Record<string, unknown>) => {
      if (isViaRelation) {
        return api.post<{ data: { id: string | number }; meta?: { message?: string } }>(
          `/api/resources/${viaResource}/${viaResourceId}/${viaRelationshipType}/${viaRelationship}`,
          data,
        )
      }
      const payload = isReplicate ? { ...data, fromResourceId } : data
      if (hasFileValues(payload)) {
        return api.upload<{ data: { id: string | number }; meta?: { message?: string } }>('POST', `/api/resources/${resource}`, payload)
      }
      return api.post<{ data: { id: string | number }; meta?: { message?: string } }>(`/api/resources/${resource}`, payload)
    },
    onSuccess: (res) => {
      void qc.invalidateQueries({ queryKey: ['resources', resource] })
      addToast('success', res.meta?.message ?? tMsg('record_created'))
      // Suppress the unsaved-changes guard for the post-save redirect.
      markSaved()
      // Clear form
      setValues({})
      setErrors({})
      // Navigate to the newly created record. When the create flow was
      // launched from a nested relation panel, prefer the `from` URL so
      // the user returns to the exact page they clicked from (which may
      // sit higher up the tree than the immediate viaResource).
      const fromParam = searchParams.get('from')
      if (fromParam) {
        navigate(fromParam)
      } else if (isViaRelation && redirectMode === 'parent') {
        navigate(`/resources/${viaResource}/${viaResourceId}`)
      } else {
        navigate(`/resources/${resource}/${res.data.id}`)
      }
    },
    onError: (err) => {
      if (err instanceof ApiError && err.errors && err.errors.length > 0) {
        const errorDisplay = schema?.errorDisplay ?? 'inline'
        if (errorDisplay === 'inline') {
          setErrors(err.errorsByField())
          addToast('error', err.message || tMsg('validation_errors', 'Please fix the errors below.'))
        } else {
          // toast mode: show all errors as individual toasts
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

  if (schemaQuery.isLoading || (isReplicate && replicateQuery.isLoading)) return <FormSkeleton />

  if (!schema) {
    return (
      <div className="rounded-lg border p-6 martis-border" style={{ backgroundColor: 'var(--martis-surface)', color: 'var(--martis-danger)' }}>
        {tMsg('error_schema')}
      </div>
    )
  }

  // Check for create override — pass full standardized OverrideProps
  if (schema.overrides?.create) {
    const OverrideComponent = componentRegistry.resolve(schema.overrides.create.component)
    if (OverrideComponent) {
      const C = OverrideComponent as React.ComponentType<OverrideProps>
      const overrideProps: OverrideProps = {
        schema,
        resource: resource!,
        params: schema.overrides.create.params ?? {},
        record: null,
        recordId: null,
        navigate: (to: string) => navigate(to),
        onClose: () => navigate(`/resources/${resource}`),
        onCreated: (rec) => {
          void qc.invalidateQueries({ queryKey: ['resources', resource] })
          addToast('success', schema.messages?.created ?? 'Record created successfully.')
          const target = resolveRedirect(schema.overrides?.create?.redirectAfter, resource!, rec.id)
          if (target) navigate(target)
        },
        onUpdated: (rec) => {
          void qc.invalidateQueries({ queryKey: ['resources', resource] })
          addToast('success', schema.messages?.updated ?? 'Record updated successfully.')
          const target = resolveRedirect(schema.overrides?.create?.redirectAfter, resource!, rec.id)
          if (target) navigate(target)
        },
        onDeleted: () => {
          void qc.invalidateQueries({ queryKey: ['resources', resource] })
          addToast('success', schema.messages?.deleted ?? 'Record deleted successfully.')
          navigate(`/resources/${resource}`)
        },
        onEdit: (id) => { if (id) navigate(`/resources/${resource}/${id}/edit`) },
        onView: (id) => navigate(`/resources/${resource}/${id}`),
        addToast,
      }
      return <C {...overrideProps} />
    }
  }

  return (
    <div className="space-y-6">
      {/* Breadcrumb */}
      <nav className="flex items-center gap-2 text-sm">
        <Link
          to={`/resources/${resource}`}
          className="inline-flex items-center gap-1.5 rounded-md px-2 py-1 font-medium transition-colors no-underline"
          style={{
            color: "var(--martis-primary)",
            backgroundColor: "transparent",
          }}
          onMouseEnter={(e) => (e.currentTarget.style.backgroundColor = "var(--martis-hover)")}
          onMouseLeave={(e) => (e.currentTarget.style.backgroundColor = "transparent")}
        >
          <ArrowLeftIcon size={14} weight="bold" />
          <ResourceIcon iconName={((schema as unknown as { icon?: string }).icon)} size={14} />
          {schema.label}
        </Link>
        <span style={{ color: "var(--martis-text-muted)" }}>/</span>
        <span className="font-semibold" style={{ color: "var(--martis-text)" }}>
          {tAct('create')} {schema.singularLabel}
        </span>
      </nav>

      <h1 className="text-2xl font-bold" style={{ color: "var(--martis-text)" }}>
        {tAct('create')} {schema.singularLabel}
      </h1>

      <form onSubmit={handleSubmit} noValidate>
        <div className="rounded-xl border" style={{ borderColor: 'var(--martis-border)', backgroundColor: 'var(--martis-surface)' }}>
          {/* Fields rendered in declaration order — layout containers and scalar fields interleaved */}
          <div className="martis-form-body martis-form-stack">
            {allFormFields.map((raw, idx) => {
              const item = raw as { type?: string } & Record<string, unknown>
              if (item.type === 'tab_group') {
                return <TabsInput key={idx} tabGroup={item as unknown as TabGroupDefinition} values={values} onChange={handleChange} errors={errors} resourceKey={resource} context="create" />
              }
              if (item.type === 'section') {
                return <SectionInput key={idx} section={item as unknown as SectionDefinition} values={values} onChange={handleChange} errors={errors} resourceKey={resource} context="create" />
              }
              if (item.type === 'panel') {
                return <PanelInput key={idx} panel={item as unknown as PanelDefinition} values={values} onChange={handleChange} errors={errors} resourceKey={resource} context="create" />
              }
              // Scalar field
              const field = item as FieldDefinition
              return (
                <FieldWrapper
                  key={field.attribute}
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
                    context="create"
                    formValues={values}
                  />
                </FieldWrapper>
              )
            })}
          </div>

          {/* Footer */}
          <div className="flex justify-end gap-3 rounded-b-xl border-t px-6 py-4" style={{ borderColor: 'var(--martis-border)', backgroundColor: 'var(--martis-surface-alt)' }}>
            <button
              type="button"
              onClick={() => {
                // Nested create/edit screens pass a `from` query param with
                // the exact URL the user clicked from. Using that beats
                // navigate(-1): it survives hard reloads and respects the
                // click origin even when the immediate parent differs from
                // the page the user was actually viewing (e.g. a task
                // created from a team-member's nested HasOneThrough panel
                // has viaResource=projects but the return target is the
                // team-member page).
                const fromParam = searchParams.get('from')
                if (fromParam) {
                  navigate(fromParam)
                } else if (window.history.length > 1) {
                  navigate(-1)
                } else if (isViaRelation) {
                  navigate(`/resources/${viaResource}/${viaResourceId}`)
                } else {
                  navigate(`/resources/${resource}`)
                }
              }}
              className="martis-btn-secondary"
            >
              {tAct('cancel')}
            </button>
            <button
              type="submit"
              disabled={createMutation.isPending}
              className="martis-btn-primary"
            >
              {createMutation.isPending ? tAct('saving') : `${tAct('create')} ${schema.singularLabel}`}
            </button>
          </div>
        </div>
      </form>
      {unsavedGuardDialog}
    </div>
  )
}

function FormSkeleton() {
  return (
    <div className="space-y-6 animate-pulse">
      <div className="h-8 w-48 rounded" style={{ backgroundColor: 'var(--martis-surface)' }} />
      <div className="rounded-xl border martis-border">
        {Array.from({ length: 4 }).map((_, i) => (
          <div key={i} className="grid grid-cols-3 gap-4 border-b px-6 py-4" style={{ borderColor: 'var(--martis-border)' }}>
            <div className="h-4 w-24 rounded" style={{ backgroundColor: 'var(--martis-surface)' }} />
            <div className="col-span-2 h-10 rounded" style={{ backgroundColor: 'var(--martis-surface)' }} />
          </div>
        ))}
      </div>
    </div>
  )
}
