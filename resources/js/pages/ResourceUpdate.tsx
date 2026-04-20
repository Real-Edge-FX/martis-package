import { useEffect, useRef, useState } from 'react'
import { useParams, useNavigate, Link, useSearchParams } from 'react-router-dom'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { api, ApiError, hasFileValues } from '@/lib/api'
import type { ResourceRecord, ResourceSchema, OverrideProps, FieldDefinition, PanelDefinition, TabGroupDefinition, SectionDefinition } from '@/types'
import { FieldInput } from '@/components/fields/FieldRenderer'
import { PanelInput } from '@/components/fields/PanelRenderer'
import { SectionInput } from '@/components/fields/SectionRenderer'
import { TabsInput } from '@/components/fields/TabsRenderer'
import { FieldLabelTooltip } from '@/components/fields/FieldLabelTooltip'
import { useToast } from '@/contexts/ToastContext'
import { useTranslation } from 'react-i18next'
import { ArrowLeftIcon } from '@phosphor-icons/react'
import { ResourceIcon } from '@/components/ResourceIcon'
import { NotFoundPage } from '@/pages/NotFound'
import { componentRegistry } from '@/lib/componentRegistry'
import { resolveRedirect } from '@/lib/resolveRedirect'
import { useUnsavedChangesGuard } from '@/lib/useUnsavedChangesGuard'
import { usePageTitle } from '@/hooks/usePageTitle'

export function ResourceUpdatePage() {
  const { resource, id } = useParams<{ resource: string; id: string }>()
  const navigate = useNavigate()
  const qc = useQueryClient()
  const { addToast } = useToast()
  const { t: tAct } = useTranslation('actions')
  const { t: tMsg } = useTranslation('messages')
  const [searchParams] = useSearchParams()
  const viaResource = searchParams.get('viaResource')
  const viaResourceId = searchParams.get('viaResourceId')
  const viaRelationship = searchParams.get('viaRelationship')
  const viaRelationshipType = searchParams.get('viaRelationshipType')
  const isViaRelation = !!(viaResource && viaResourceId && viaRelationship)
  // Backwards-compat alias — some code below still uses this flag to
  // decide the "back to parent" redirect. Preserves the semantics (any
  // relationship, not just HasMany) but keeps the original name.
  const isViaHasMany = isViaRelation
  const redirectMode = searchParams.get('redirectMode') ?? 'parent'

  const schemaQuery = useQuery({
    queryKey: ['schema', resource],
    queryFn: () => api.get<{ data: ResourceSchema }>(`/api/resources/${resource}/schema`),
    enabled: !!resource,
  })

  const recordQuery = useQuery({
    queryKey: ['resource', resource, id, 'update'],
    queryFn: () => api.get<{ data: ResourceRecord }>(`/api/resources/${resource}/${id}?context=update`),
    enabled: !!resource && !!id,
  })

  const schema = schemaQuery.data?.data
  const record = recordQuery.data?.data
  const { t: tNav } = useTranslation('navigation')
  usePageTitle(schema ? `${tNav('edit', { defaultValue: 'Edit' })} ${schema.singularLabel}` : null)

  const allFormFields = (schema?.fieldsForUpdate ?? [])

  const [values, setValues] = useState<Record<string, unknown>>({})
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [initialized, setInitialized] = useState(false)
  const baselineRef = useRef<string | null>(null)

  // Pre-populate form only after BOTH schema and record are loaded.
  // Walks all form items (scalar fields + layout containers) to extract field attributes.
  useEffect(() => {
    if (record && schema && !initialized) {
      const initial: Record<string, unknown> = {}
      const extractFields = (items: unknown[]) => {
        for (const item of items as Array<Record<string, unknown>>) {
          if (item.type === 'panel' || item.type === 'section') {
            ((item.fields ?? []) as FieldDefinition[]).forEach((f) => { initial[f.attribute] = record[f.attribute] ?? null })
          } else if (item.type === 'tab_group') {
            (((item as unknown as TabGroupDefinition).tabs) ?? []).forEach((tab) => {
              tab.fields.forEach((f) => {
                if ('attribute' in f) { initial[(f as FieldDefinition).attribute] = record[(f as FieldDefinition).attribute] ?? null }
                else { ((f as PanelDefinition).fields ?? []).forEach((pf: FieldDefinition) => { initial[pf.attribute] = record[pf.attribute] ?? null }) }
              })
            })
          } else {
            const f = item as unknown as FieldDefinition
            if (f.attribute) { initial[f.attribute] = record[f.attribute] ?? null }
          }
        }
      }
      extractFields(allFormFields)
      baselineRef.current = JSON.stringify(initial)
      setValues(initial)
      setInitialized(true)
    }
  }, [record, schema, allFormFields, initialized])

  const { dialog: unsavedGuardDialog, markSaved } = useUnsavedChangesGuard({
    values,
    initialSnapshot: initialized ? baselineRef.current : null,
    schema,
  })

  const updateMutation = useMutation({
    mutationFn: (data: Record<string, unknown>) => {
      if (hasFileValues(data)) {
        return api.upload<{ data: ResourceRecord; meta?: { message?: string } }>('PUT', `/api/resources/${resource}/${id}`, data)
      }
      return api.put<{ data: ResourceRecord; meta?: { message?: string } }>(`/api/resources/${resource}/${id}`, data)
    },
    onSuccess: (res) => {
      void qc.invalidateQueries({ queryKey: ['resources', resource] })
      void qc.invalidateQueries({ queryKey: ['resource', resource, id] })
      addToast('success', res.meta?.message ?? tMsg('record_updated'))
      // Suppress the unsaved-changes guard for the post-save redirect.
      markSaved()
      // Navigate back to parent resource detail if editing via a
      // relationship, otherwise to record detail. Invalidate the matching
      // query (has-many or has-one depending on viaRelationshipType),
      // otherwise the parent's panel would keep stale data and the user
      // would need a manual refresh.
      if (isViaRelation) {
        if (viaRelationshipType === 'has-one') {
          void qc.invalidateQueries({ queryKey: ['has-one', viaResource, viaResourceId, viaRelationship] })
        } else {
          void qc.invalidateQueries({ queryKey: ['has-many', viaResource, viaResourceId, viaRelationship] })
        }
      }
      // Prefer the explicit `from` URL so the user returns to the exact
      // page they clicked from, even when that page sits above the
      // immediate parent in the resource tree.
      const fromParam = searchParams.get('from')
      if (fromParam) {
        navigate(fromParam)
      } else if (isViaRelation && redirectMode === 'parent') {
        navigate(`/resources/${viaResource}/${viaResourceId}`)
      } else {
        navigate(`/resources/${resource}/${id}`)
      }
    },
    onError: (err) => {
      if (err instanceof ApiError && err.errors && err.errors.length > 0) {
        const errorDisplay = schema?.errorDisplay ?? 'inline'
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
    // Filter values: skip file/image fields that haven't changed (still object from API)
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
      // BelongsTo: if value is still the original {id, title} object, extract just the ID
      if (typeof val === 'object' && !(val instanceof File) && 'id' in (val as Record<string, unknown>) && 'title' in (val as Record<string, unknown>)) {
        submitValues[key] = (val as Record<string, unknown>).id
        continue
      }
      submitValues[key] = val
    }
    updateMutation.mutate(submitValues)
  }

  if (schemaQuery.isLoading || recordQuery.isLoading) return <FormSkeleton />

  if (!schema) {
    return <NotFoundPage />
  }

  if (!record) {
    return <NotFoundPage />
  }

  // Check for update override — pass full standardized OverrideProps
  if (schema.overrides?.update) {
    const OverrideComponent = componentRegistry.resolve(schema.overrides.update.component)
    if (OverrideComponent) {
      const C = OverrideComponent as React.ComponentType<OverrideProps>
      const overrideProps: OverrideProps = {
        schema,
        resource: resource!,
        params: schema.overrides.update.params ?? {},
        record,
        recordId: id ?? null,
        navigate: (to: string) => navigate(to),
        onClose: () => navigate(`/resources/${resource}/${id}`),
        onCreated: (rec) => {
          void qc.invalidateQueries({ queryKey: ['resources', resource] })
          addToast('success', schema.messages?.created ?? 'Record created successfully.')
          const target = resolveRedirect(schema.overrides?.update?.redirectAfter, resource!, rec.id)
          if (target) navigate(target)
        },
        onUpdated: (rec) => {
          void qc.invalidateQueries({ queryKey: ['resources', resource] })
          void qc.invalidateQueries({ queryKey: ['resource', resource, id] })
          addToast('success', schema.messages?.updated ?? 'Record updated successfully.')
          const target = resolveRedirect(schema.overrides?.update?.redirectAfter, resource!, rec.id)
          if (target) navigate(target)
        },
        onDeleted: () => {
          void qc.invalidateQueries({ queryKey: ['resources', resource] })
          addToast('success', schema.messages?.deleted ?? 'Record deleted successfully.')
          navigate(`/resources/${resource}`)
        },
        onEdit: (editId) => {
          const targetId = editId ?? id
          if (targetId) navigate(`/resources/${resource}/${targetId}/edit`)
        },
        onView: (viewId) => navigate(`/resources/${resource}/${viewId}`),
        addToast,
      }
      return <C {...overrideProps} />
    }
  }

  // Back link: go to parent detail when via HasMany, otherwise to record detail
  const backLink = isViaHasMany
    ? `/resources/${viaResource}/${viaResourceId}`
    : `/resources/${resource}/${id}`
  const backLabel = isViaHasMany
    ? `← ${viaResource} #${viaResourceId}`
    : (record._title ? String(record._title) : `${schema.singularLabel} #${id}`)

  // Cancel link: go back to parent if via HasMany
  const cancelLink = isViaHasMany
    ? `/resources/${viaResource}/${viaResourceId}`
    : `/resources/${resource}/${id}`

  return (
    <div className="space-y-6">
      {/* Breadcrumb */}
      <nav className="flex items-center gap-2 text-sm">
        <Link
          to={backLink}
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
          {backLabel}
        </Link>
        <span style={{ color: "var(--martis-text-muted)" }}>/</span>
        <span className="font-semibold" style={{ color: "var(--martis-text)" }}>
          {tAct('edit')} {schema.singularLabel}
        </span>
      </nav>

      <h1 className="text-2xl font-bold" style={{ color: "var(--martis-text)" }}>
        {tAct('edit')} {schema.singularLabel}
      </h1>

      <form onSubmit={handleSubmit} noValidate>
        <div className="rounded-xl border" style={{ borderColor: 'var(--martis-border)', backgroundColor: 'var(--martis-surface)' }}>
          {/* Fields rendered in declaration order */}
          <div className="p-6 space-y-4">
            {allFormFields.map((item, idx) => {
              if (item.type === 'tab_group') {
                return <TabsInput key={idx} tabGroup={item as TabGroupDefinition} values={values} onChange={handleChange} errors={errors} resourceKey={resource} recordId={id} context="update" />
              }
              if (item.type === 'section') {
                return <SectionInput key={idx} section={item as SectionDefinition} values={values} onChange={handleChange} errors={errors} resourceKey={resource} recordId={id} context="update" />
              }
              if (item.type === 'panel') {
                return <PanelInput key={idx} panel={item as PanelDefinition} values={values} onChange={handleChange} errors={errors} resourceKey={resource} recordId={id} context="update" />
              }
              const field = item as FieldDefinition
              return (
                <div key={field.attribute} className="grid grid-cols-3 gap-4">
                  <div>
                    <label
                      htmlFor={field.attribute}
                      className="block text-sm font-medium"
                      style={{ color: 'var(--martis-text-muted)' }}
                    >
                      {field.label}
                      {field.required && (
                        <span className="ml-1 text-red-500" aria-hidden="true">*</span>
                      )}
                      <FieldLabelTooltip text={field.tooltip} />
                    </label>
                  </div>
                  <div className="col-span-2">
                    <FieldInput
                      field={field}
                      value={values[field.attribute] ?? null}
                      onChange={(v) => handleChange(field.attribute, v)}
                      error={errors[field.attribute]}
                      resourceKey={resource}
                      recordId={id ?? undefined}
                      context="update"
                      formValues={values}
                    />
                    {field.helpText && (
                      <p className="mt-1 text-xs" style={{ color: 'var(--martis-text-muted)' }} dangerouslySetInnerHTML={{ __html: field.helpText }} />
                    )}
                  </div>
                </div>
              )
            })}
          </div>

          {/* Footer */}
          <div className="flex justify-end gap-3 rounded-b-xl border-t px-6 py-4"
            style={{
              borderColor: 'var(--martis-border)',
              backgroundColor: 'var(--martis-surface-alt)',
            }}>
            <button
              type="button"
              onClick={() => {
                // See ResourceCreate cancel handler — same rationale for
                // preferring the `from` param over navigate(-1).
                const fromParam = searchParams.get('from')
                if (fromParam) {
                  navigate(fromParam)
                } else if (window.history.length > 1) {
                  navigate(-1)
                } else {
                  navigate(cancelLink)
                }
              }}
              className="martis-btn-secondary"
            >
              {tAct('cancel')}
            </button>
            <button
              type="submit"
              disabled={updateMutation.isPending}
              className="martis-btn-primary"
            >
              {updateMutation.isPending ? tAct('saving') : tAct('save')}
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
      <div className="h-8 w-48 rounded bg-gray-200 dark:bg-gray-800" />
      <div className="rounded-xl border border-gray-200 dark:border-gray-800">
        {Array.from({ length: 4 }).map((_, i) => (
          <div key={i} className="grid grid-cols-3 gap-4 border-b border-gray-100 px-6 py-4 dark:border-gray-800">
            <div className="h-4 w-24 rounded bg-gray-200 dark:bg-gray-700" />
            <div className="col-span-2 h-10 rounded bg-gray-200 dark:bg-gray-700" />
          </div>
        ))}
      </div>
    </div>
  )
}
