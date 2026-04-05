import { useState } from 'react'
import { useNavigate, Link } from 'react-router-dom'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { api, ApiError, hasFileValues } from '@/lib/api'
import { FieldInput } from '@/components/fields'
import { useToast } from '@/contexts/ToastContext'
import { useTranslation } from 'react-i18next'
import { ArrowLeft, X, FloppyDisk, ListBullets } from '@phosphor-icons/react'
import { ResourceIcon } from '@/components/ResourceIcon'
import type { CreatePageProps } from '@/lib/pageRegistry'

/**
 * Demo: Custom Create page for "projects" resource.
 *
 * Instead of the default full-page form, this renders a sidebar-style panel
 * that slides in from the right, with fields loaded dynamically from the
 * resource schema. Demonstrates the pageRegistry override system.
 */
export function DemoProjectCreate({ resourceKey, schema, fields }: CreatePageProps) {
  const navigate = useNavigate()
  const qc = useQueryClient()
  const { addToast } = useToast()
  const { t: tAct } = useTranslation('actions')
  const { t: tMsg } = useTranslation('messages')
  const [values, setValues] = useState<Record<string, unknown>>({})
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [sidebarOpen, setSidebarOpen] = useState(true)

  const createMutation = useMutation({
    mutationFn: (data: Record<string, unknown>) => {
      if (hasFileValues(data)) {
        return api.upload<{ data: { id: string | number }; meta?: { message?: string } }>('POST', `/api/resources/${resourceKey}`, data)
      }
      return api.post<{ data: { id: string | number }; meta?: { message?: string } }>(`/api/resources/${resourceKey}`, data)
    },
    onSuccess: (res) => {
      void qc.invalidateQueries({ queryKey: ['resources', resourceKey] })
      addToast('success', res.meta?.message ?? tMsg('record_created'))
      navigate(`/resources/${resourceKey}/${res.data.id}`)
    },
    onError: (err) => {
      if (err instanceof ApiError && err.errors && err.errors.length > 0) {
        setErrors(err.errorsByField())
        addToast('error', err.message || tMsg('validation_errors', 'Please fix the errors below.'))
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

  function handleClose() {
    setSidebarOpen(false)
    setTimeout(() => navigate(`/resources/${resourceKey}`), 300)
  }

  return (
    <div className="relative flex h-full min-h-[calc(100vh-8rem)]">
      {/* Main content area — shows resource info / empty state */}
      <div className="flex-1 p-6">
        <nav className="flex items-center gap-2 text-sm mb-6">
          <Link
            to={`/resources/${resourceKey}`}
            className="inline-flex items-center gap-1.5 rounded-md px-2 py-1 font-medium transition-colors no-underline"
            style={{ color: "var(--martis-primary)" }}
          >
            <ArrowLeft size={14} weight="bold" />
            <ResourceIcon iconName={((schema as unknown as { icon?: string }).icon)} size={14} />
            {schema.label}
          </Link>
        </nav>

        <div
          className="flex flex-col items-center justify-center rounded-xl border-2 border-dashed p-12"
          style={{ borderColor: 'var(--martis-border)', backgroundColor: 'var(--martis-surface)' }}
        >
          <div
            className="flex h-16 w-16 items-center justify-center rounded-full mb-4"
            style={{ backgroundColor: 'var(--martis-hover)' }}
          >
            <ListBullets size={32} style={{ color: 'var(--martis-primary)' }} />
          </div>
          <h2 className="text-lg font-semibold mb-2" style={{ color: 'var(--martis-text)' }}>
            {tAct('create')} {schema.singularLabel}
          </h2>
          <p className="text-sm text-center max-w-md" style={{ color: 'var(--martis-text-muted)' }}>
            This is a custom Create page using the <strong>pageRegistry</strong> override.
            The form fields are loaded from the resource schema and displayed in a sidebar panel.
          </p>
          {!sidebarOpen && (
            <button
              type="button"
              onClick={() => setSidebarOpen(true)}
              className="mt-4 rounded-md px-4 py-2 text-sm font-medium text-white"
              style={{ backgroundColor: 'var(--martis-accent)' }}
            >
              Open Form
            </button>
          )}
        </div>
      </div>

      {/* Sidebar panel */}
      <div
        className="fixed inset-y-0 right-0 z-40 flex transition-transform duration-300 ease-in-out"
        style={{ transform: sidebarOpen ? 'translateX(0)' : 'translateX(100%)' }}
      >
        {/* Overlay */}
        {sidebarOpen && (
          <div
            className="fixed inset-0 transition-opacity"
            style={{ backgroundColor: 'rgba(0,0,0,0.3)' }}
            onClick={handleClose}
          />
        )}

        {/* Panel */}
        <div
          className="relative ml-auto flex h-full w-[480px] max-w-[90vw] flex-col border-l shadow-xl"
          style={{
            borderColor: 'var(--martis-border)',
            backgroundColor: 'var(--martis-card)',
          }}
        >
          {/* Header */}
          <div
            className="flex items-center justify-between border-b px-6 py-4"
            style={{ borderColor: 'var(--martis-border)', backgroundColor: 'var(--martis-surface)' }}
          >
            <div className="flex items-center gap-2">
              <ResourceIcon iconName={((schema as unknown as { icon?: string }).icon)} size={18} />
              <h2 className="text-lg font-semibold" style={{ color: 'var(--martis-text)' }}>
                {tAct('create')} {schema.singularLabel}
              </h2>
            </div>
            <button
              type="button"
              onClick={handleClose}
              className="rounded-md p-1.5 transition-colors"
              style={{ color: 'var(--martis-text-muted)' }}
            >
              <X size={18} />
            </button>
          </div>

          {/* Form fields */}
          <form onSubmit={handleSubmit} noValidate className="flex flex-1 flex-col overflow-hidden">
            <div className="flex-1 overflow-y-auto px-6 py-4 space-y-5">
              {fields.map((field) => (
                <div key={field.attribute}>
                  <label
                    htmlFor={field.attribute}
                    className="block text-sm font-medium mb-1.5"
                    style={{ color: 'var(--martis-text)' }}
                  >
                    {field.label}
                    {field.required && (
                      <span className="ml-1 text-red-500" aria-hidden="true">*</span>
                    )}
                  </label>
                  <FieldInput
                    field={field}
                    value={values[field.attribute] ?? null}
                    onChange={(v) => handleChange(field.attribute, v)}
                    error={errors[field.attribute]}
                    resourceKey={resourceKey}
                  />
                </div>
              ))}
            </div>

            {/* Footer actions */}
            <div
              className="flex items-center justify-end gap-3 border-t px-6 py-4"
              style={{ borderColor: 'var(--martis-border)', backgroundColor: 'var(--martis-surface)' }}
            >
              <button
                type="button"
                onClick={handleClose}
                className="rounded-md border px-4 py-2 text-sm font-medium"
                style={{
                  borderColor: 'var(--martis-border)',
                  backgroundColor: 'var(--martis-input-bg)',
                  color: 'var(--martis-text-muted)',
                }}
              >
                {tAct('cancel')}
              </button>
              <button
                type="submit"
                disabled={createMutation.isPending}
                className="inline-flex items-center gap-2 rounded-md px-4 py-2 text-sm font-medium text-white disabled:opacity-60"
                style={{ backgroundColor: 'var(--martis-accent)' }}
              >
                <FloppyDisk size={16} />
                {createMutation.isPending ? tAct('saving') : tAct('create')}
              </button>
            </div>
          </form>
        </div>
      </div>
    </div>
  )
}
