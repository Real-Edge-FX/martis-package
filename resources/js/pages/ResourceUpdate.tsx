import { useMemo, useEffect, useState } from 'react'
import { useParams, useNavigate, Link } from 'react-router-dom'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { api, ApiError } from '@/lib/api'
import type { ResourceRecord, ResourceSchema } from '@/types'
import { FieldInput } from '@/components/fields'
import { useToast } from '@/contexts/ToastContext'
import { useTranslation } from 'react-i18next'
import { BASE_PATH } from "@/lib/config"

export function ResourceUpdatePage() {
  const { resource, id } = useParams<{ resource: string; id: string }>()
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

  const recordQuery = useQuery({
    queryKey: ['resource', resource, id],
    queryFn: () => api.get<{ data: ResourceRecord }>(`/api/resources/${resource}/${id}`),
    enabled: !!resource && !!id,
  })

  const schema = schemaQuery.data?.data
  const record = recordQuery.data?.data

  // Stable reference — recomputed only when schema changes
  const formFields = useMemo(
    () => schema?.fields.filter((f) => f.showOnForms) ?? [],
    [schema],
  )

  const [values, setValues] = useState<Record<string, unknown>>({})
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [initialized, setInitialized] = useState(false)

  // Pre-populate form only after BOTH schema and record are loaded
  useEffect(() => {
    if (record && schema && !initialized) {
      const initial: Record<string, unknown> = {}
      formFields.forEach((field) => {
        initial[field.attribute] = record[field.attribute] ?? null
      })
      setValues(initial)
      setInitialized(true)
    }
  }, [record, schema, formFields, initialized])

  const updateMutation = useMutation({
    mutationFn: (data: Record<string, unknown>) =>
      api.put<{ data: ResourceRecord }>(`/api/resources/${resource}/${id}`, data),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ['resources', resource] })
      void qc.invalidateQueries({ queryKey: ['resource', resource, id] })
      addToast('success', tMsg('record_updated'))
      navigate(`${BASE_PATH}/resources/${resource}/${id}`)
    },
    onError: (err) => {
      if (err instanceof ApiError && err.errors) {
        const fieldErrors: Record<string, string> = {}
        Object.entries(err.errors).forEach(([attr, messages]) => {
          fieldErrors[attr] = messages[0]?.message ?? tMsg('invalid_field')
        })
        setErrors(fieldErrors)
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
    updateMutation.mutate(values)
  }

  if (schemaQuery.isLoading || recordQuery.isLoading) return <FormSkeleton />

  if (!schema || !record) {
    return (
      <div className="rounded-lg border border-red-200 bg-red-50 p-6 text-red-700 dark:border-red-800">
        {tMsg('record_not_found')}
      </div>
    )
  }

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex items-center gap-3">
        <Link
          to={`${BASE_PATH}/resources/${resource}/${id}`}
          className="text-sm text-blue-600 hover:underline dark:text-blue-400"
        >
          ← {schema.singularLabel} #{id}
        </Link>
        <span className="text-gray-300 dark:text-gray-600">/</span>
        <h1 className="text-2xl font-bold text-gray-900 dark:text-white">
          {tAct('edit')} {schema.singularLabel}
        </h1>
      </div>

      <form onSubmit={handleSubmit} noValidate>
        <div className="rounded-xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-950">
          <div className="divide-y divide-gray-100 dark:divide-gray-800">
            {formFields.map((field) => (
              <div key={field.attribute} className="grid grid-cols-3 gap-4 px-6 py-4">
                <div>
                  <label
                    htmlFor={field.attribute}
                    className="block text-sm font-medium text-gray-700 dark:text-gray-300"
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

          <div className="flex justify-end gap-3 rounded-b-xl border-t border-gray-100 bg-gray-50 px-6 py-4 dark:border-gray-800 dark:bg-gray-900">
            <Link
              to={`${BASE_PATH}/resources/${resource}/${id}`}
              className="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300"
            >
              {tAct('cancel')}
            </Link>
            <button
              type="submit"
              disabled={updateMutation.isPending}
              className="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-60"
            >
              {updateMutation.isPending ? tAct('saving') : tAct('save')}
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
