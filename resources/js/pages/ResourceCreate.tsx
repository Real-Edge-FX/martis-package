import { useMemo, useState } from 'react'
import { useParams, useNavigate, Link } from 'react-router-dom'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { api, ApiError, hasFileValues } from '@/lib/api'
import type { ResourceSchema } from '@/types'
import { FieldInput } from '@/components/fields'
import { useToast } from '@/contexts/ToastContext'
import { useTranslation } from 'react-i18next'
import { ArrowLeft } from '@phosphor-icons/react'
import { ResourceIcon } from '@/components/ResourceIcon'

export function ResourceCreatePage() {
  const { resource } = useParams<{ resource: string }>()
  const navigate = useNavigate()
  const qc = useQueryClient()
  const { addToast } = useToast()
  const { t: tAct } = useTranslation('actions')
  const { t: tMsg } = useTranslation('messages')

  const schemaQuery = useQuery({
    queryKey: ['schema', resource],
    queryFn: () => api.get<{ data: ResourceSchema }>(`/api/resources/${resource}/schema`),
    enabled: !!resource,
  })

  const schema = schemaQuery.data?.data
  const formFields = useMemo(
    () => schema?.fields.filter((f) => f.showOnForms) ?? [],
    [schema],
  )

  const [values, setValues] = useState<Record<string, unknown>>({})
  const [errors, setErrors] = useState<Record<string, string>>({})

  const createMutation = useMutation({
    mutationFn: (data: Record<string, unknown>) => {
      if (hasFileValues(data)) {
        return api.upload<{ data: { id: string | number }; meta?: { message?: string } }>('POST', `/api/resources/${resource}`, data)
      }
      return api.post<{ data: { id: string | number }; meta?: { message?: string } }>(`/api/resources/${resource}`, data)
    },
    onSuccess: (res) => {
      void qc.invalidateQueries({ queryKey: ['resources', resource] })
      addToast('success', res.meta?.message ?? tMsg('record_created'))
      // Clear form
      setValues({})
      setErrors({})
      // Navigate to the newly created record
      navigate(`/resources/${resource}/${res.data.id}`)
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

  if (schemaQuery.isLoading) return <FormSkeleton />

  if (!schema) {
    return (
      <div className="rounded-lg border p-6 martis-border" style={{ backgroundColor: 'var(--martis-surface)', color: '#ef4444' }}>
        {tMsg('error_schema')}
      </div>
    )
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
          <ArrowLeft size={14} weight="bold" />
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
        <div className="martis-card-bg rounded-xl border martis-border">
          <div className="divide-y" style={{ borderColor: 'var(--martis-border)' }}>
            {formFields.map((field) => (
              <div key={field.attribute} className="grid grid-cols-3 gap-4 px-6 py-4" style={{ borderColor: 'var(--martis-border)' }}>
                <div>
                  <label
                    htmlFor={field.attribute}
                    className="block text-sm font-medium martis-text-muted"
                  >
                    {field.label}
                    {field.required && (
                      <span className="ml-1 text-red-500" aria-hidden="true">
                        *
                      </span>
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
                  />
                </div>
              </div>
            ))}
          </div>

          <div className="flex justify-end gap-3 rounded-b-xl border-t px-6 py-4" style={{ borderColor: 'var(--martis-border)', backgroundColor: 'var(--martis-surface)' }}>
            <Link
              to={`/resources/${resource}`}
              className="rounded-md border px-4 py-2 text-sm font-medium martis-text-muted martis-border"
              style={{ backgroundColor: 'var(--martis-input-bg)', borderColor: 'var(--martis-border)' }}
            >
              {tAct('cancel')}
            </Link>
            <button
              type="submit"
              disabled={createMutation.isPending}
              className="rounded-md px-4 py-2 text-sm font-medium text-white disabled:opacity-60"
              style={{ backgroundColor: 'var(--martis-accent)' }}
            >
              {createMutation.isPending ? tAct('saving') : `${tAct('create')} ${schema.singularLabel}`}
            </button>
          </div>
        </div>
      </form>
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
