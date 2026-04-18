import { useEffect, useMemo, useRef, useState } from 'react'
import { useParams, useNavigate, Link, useSearchParams } from 'react-router-dom'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { api, ApiError, hasFileValues } from '@/lib/api'
import type { ResourceSchema, OverrideProps, FieldDefinition, PanelDefinition, TabGroupDefinition, SectionDefinition } from '@/types'
import { FieldInput } from '@/components/fields/FieldRenderer'
import { PanelInput } from '@/components/fields/PanelRenderer'
import { SectionInput } from '@/components/fields/SectionRenderer'
import { TabsInput } from '@/components/fields/TabsRenderer'
import { useToast } from '@/contexts/ToastContext'
import { useTranslation } from 'react-i18next'
import { ArrowLeftIcon } from '@phosphor-icons/react'
import { ResourceIcon } from '@/components/ResourceIcon'
import { componentRegistry } from '@/lib/componentRegistry'
import { resolveRedirect } from '@/lib/resolveRedirect'
import { useUnsavedChangesGuard } from '@/lib/useUnsavedChangesGuard'

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

  // Fetch pre-fill data when replicating (Nova v5 parity)
  const replicateQuery = useQuery({
    queryKey: ['replicate', resource, fromResourceId],
    queryFn: () => api.get<{ data: { values: Record<string, unknown>; fromResourceId: string | number } }>(
      `/api/resources/${resource}/${fromResourceId}/replicate`
    ),
    enabled: !!resource && isReplicate,
  })

  const schema = schemaQuery.data?.data
  const allFormFields = (schema?.fieldsForCreate ?? [])

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

  // Capture a baseline once the form has its real starting values
  // (either an empty object for a plain create, or the replicated
  // snapshot). The dirty guard compares against this baseline.
  const initialSnapshot = useMemo(() => {
    if (!schema) return null
    if (isReplicate && !replicateApplied) return null
    if (baselineRef.current === null) {
      baselineRef.current = JSON.stringify(values)
    }
    return baselineRef.current
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [schema, isReplicate, replicateApplied])

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
      // Navigate to the newly created record
      if (isViaRelation && redirectMode === 'parent') {
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
          <div className="p-6 space-y-4">
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
              // Scalar field
              const field = item as FieldDefinition
              return (
                <div key={field.attribute} className="grid grid-cols-3 gap-4" style={{ borderColor: 'var(--martis-border)' }}>
                  <div>
                    <label
                      htmlFor={field.attribute}
                      className="block text-sm font-medium martis-text-muted"
                    >
                      {field.label}
                      {field.required && (
                        <span className="ml-1 text-red-500" aria-hidden="true">*</span>
                      )}
                    </label>
                  </div>
                  <div className="col-span-2">
                    <FieldInput
                      field={field}
                      value={values[field.attribute] ?? null}
                      onChange={(v) => handleChange(field.attribute, v)}
                      error={errors[field.attribute]}
                      resourceKey={resource}
                      context="create"
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
          <div className="flex justify-end gap-3 rounded-b-xl border-t px-6 py-4" style={{ borderColor: 'var(--martis-border)', backgroundColor: 'var(--martis-surface-alt)' }}>
            <Link
              to={isViaRelation ? `/resources/${viaResource}/${viaResourceId}` : `/resources/${resource}`}
              className="martis-btn-secondary no-underline"
            >
              {tAct('cancel')}
            </Link>
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
