import { useMemo, useState } from 'react'
import { useParams, useNavigate, Link } from 'react-router-dom'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { api, ApiError } from '@/lib/api'
import type { ResourceSchema } from '@/types'
import { FieldInput } from '@/components/fields'
import { useToast } from '@/contexts/ToastContext'
import { useTranslation } from 'react-i18next'

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
    mutationFn: (data: Record<string, unknown>) =>
      api.post<{ data: { id: string | number } }>(`/api/resources/${resource}`, data),
    onSuccess: (res) => {
      void qc.invalidateQueries({ queryKey: ['resources', resource] })
      addToast('success', tMsg('record_created'))
      navigate(`/resources/${resource}/${res.data.id}`)
    },
    onError: (err) => {
      if (err instanceof ApiError && err.errors) {
        const fieldErrors: Record<string, string> = {}
        Object.entries(err.errors).forEach(([attr, messages]) => {
          fieldErrors[attr] = messages[0]?.message ?? tMsg('invalid_field')
        })
        setErrors(fieldErrors)
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
      <div className="rounded-lg border border-red-200 bg-red-50 p-6 text-red-700 dark:border-red-800">
        {tMsg('error_schema')}
      </div>
    )
  }

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex items-center gap-3">
        <Link
          to={`/resources/${resource}`}
          className="text-sm text-blue-600 hover:underline dark:text-blue-400"
        >
          ← {schema.label}
        </Link>
        <span className="text-gray-300 dark:text-gray-600">/</span>
        <h1 className="text-2xl font-bold text-gray-900 dark:text-white">
          {tAct('create')} {schema.singularLabel}
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
              to={`/resources/${resource}`}
              className="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300"
            >
              {tAct('cancel')}
            </Link>
            <button
              type="submit"
              disabled={createMutation.isPending}
              className="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-60"
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
