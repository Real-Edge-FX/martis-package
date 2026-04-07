import { useState, useEffect, useCallback } from "react"
import { createPortal } from "react-dom"
import { useQuery, useMutation } from "@tanstack/react-query"
import { api, ApiError, hasFileValues } from "@/lib/api"
import { FieldInput } from "@/components/fields"
import { useToast } from "@/contexts/ToastContext"
import { useTranslation } from "react-i18next"
import { X, Plus } from "@phosphor-icons/react"
import type { FieldDefinition } from "@/types"

/** Modal size classes matching Nova v5 modal sizes */
const MODAL_SIZE_CLASSES: Record<string, string> = {
  sm: "max-w-sm",
  md: "max-w-md",
  lg: "max-w-lg",
  xl: "max-w-xl",
  "2xl": "max-w-2xl",
  "3xl": "max-w-3xl",
  "4xl": "max-w-4xl",
  "5xl": "max-w-5xl",
  "6xl": "max-w-6xl",
  "7xl": "max-w-7xl",
}

interface InlineCreateModalProps {
  /** Resource URI key for the related resource to create */
  relatedResource: string
  /** Whether the modal is open */
  open: boolean
  /** Close handler */
  onClose: () => void
  /** Called after successful creation with the new record */
  onCreated: (record: { id: string | number; title: string | null }) => void
  /** Modal size (defaults to 2xl) */
  modalSize?: string
}

export function InlineCreateModal({
  relatedResource,
  open,
  onClose,
  onCreated,
  modalSize = "2xl",
}: InlineCreateModalProps) {
  const { addToast } = useToast()
  const { t: tAct } = useTranslation("actions")
  const { t: tMsg } = useTranslation("messages")
  const [values, setValues] = useState<Record<string, unknown>>({})
  const [errors, setErrors] = useState<Record<string, string>>({})

  // Fetch inline create schema
  const schemaQuery = useQuery({
    queryKey: ["inline-create-schema", relatedResource],
    queryFn: () =>
      api.get<{
        data: {
          fields: FieldDefinition[]
          singularLabel: string
          label: string
        }
      }>(`/api/resources/${relatedResource}/inline-create-schema`),
    enabled: open,
  })

  const createMutation = useMutation({
    mutationFn: (data: Record<string, unknown>) => {
      if (hasFileValues(data)) {
        return api.upload<{
          data: { id: string | number; title: string | null }
          meta?: { message?: string }
        }>("POST", `/api/resources/${relatedResource}/inline-create`, data)
      }
      return api.post<{
        data: { id: string | number; title: string | null }
        meta?: { message?: string }
      }>(`/api/resources/${relatedResource}/inline-create`, data)
    },
    onSuccess: (res) => {
      addToast("success", res?.meta?.message ?? tMsg("record_created"))
      setValues({})
      setErrors({})
      onCreated({ id: res.data.id, title: res.data.title })
    },
    onError: (err) => {
      if (err instanceof ApiError && err.errors && err.errors.length > 0) {
        setErrors(err.errorsByField())
        // Don't show toast for validation errors — inline errors are sufficient
      } else if (err instanceof ApiError) {
        addToast("error", err.message || tMsg("error_create"))
      } else {
        addToast("error", tMsg("error_create"))
      }
    },
  })

  function handleChange(attribute: string, value: unknown) {
    setValues((prev) => ({ ...prev, [attribute]: value }))
    if (errors[attribute]) setErrors((prev) => ({ ...prev, [attribute]: "" }))
  }

  function handleSubmit(e: React.FormEvent) {
    e.preventDefault()
    e.stopPropagation()
    setErrors({})
    createMutation.mutate(values)
  }

  // Reset state when modal opens/closes
  useEffect(() => {
    if (open) {
      setValues({})
      setErrors({})
    }
  }, [open])

  // Close on Escape
  const handleKeyDown = useCallback(
    (e: KeyboardEvent) => {
      if (e.key === "Escape" && open) {
        onClose()
      }
    },
    [open, onClose],
  )

  useEffect(() => {
    document.addEventListener("keydown", handleKeyDown)
    return () => document.removeEventListener("keydown", handleKeyDown)
  }, [handleKeyDown])

  if (!open) return null

  const schema = schemaQuery.data?.data
  const sizeClass = MODAL_SIZE_CLASSES[modalSize] ?? MODAL_SIZE_CLASSES["2xl"]

  return createPortal(
    <div
      style={{ position: "fixed", inset: 0, zIndex: 9999 }}
      className="flex items-center justify-center"
      onKeyDown={(e) => { if (e.key === "Enter") e.stopPropagation() }}
    >
      {/* Backdrop */}
      <div
        className="absolute inset-0 bg-black/50 transition-opacity"
        onClick={onClose}
      />

      {/* Modal */}
      <div
        role="dialog"
        aria-modal="true"
        className={`relative w-full ${sizeClass} rounded-xl shadow-2xl transform transition-all`}
        style={{
          backgroundColor: "var(--martis-card)",
          border: "1px solid var(--martis-border)",
          maxHeight: "85vh",
          display: "flex",
          flexDirection: "column",
        }}
      >
        {/* Header */}
        <div
          className="flex items-center justify-between border-b px-6 py-4"
          style={{ borderColor: "var(--martis-border)" }}
        >
          <div className="flex items-center gap-3">
            <div
              className="flex h-10 w-10 items-center justify-center rounded-full"
              style={{ backgroundColor: "var(--martis-primary-bg, rgba(59, 130, 246, 0.1))" }}
            >
              <Plus size={20} style={{ color: "var(--martis-primary)" }} />
            </div>
            <span
              className="text-lg font-semibold"
              style={{ color: "var(--martis-text)" }}
            >
              {tAct("create")} {schema?.singularLabel ?? relatedResource}
            </span>
          </div>
          <button
            type="button"
            onClick={onClose}
            className="rounded-md p-1 transition-colors"
            style={{ color: "var(--martis-text-muted)" }}
          >
            <X size={20} />
          </button>
        </div>

        {/* Body */}
        <div className="flex-1 overflow-y-auto px-6 py-4">
          {schemaQuery.isLoading ? (
            <div className="space-y-4 animate-pulse">
              {Array.from({ length: 3 }).map((_, i) => (
                <div key={i} className="grid grid-cols-3 gap-4">
                  <div
                    className="h-4 w-24 rounded"
                    style={{ backgroundColor: "var(--martis-surface)" }}
                  />
                  <div
                    className="col-span-2 h-10 rounded"
                    style={{ backgroundColor: "var(--martis-surface)" }}
                  />
                </div>
              ))}
            </div>
          ) : schema ? (
            <form id="inline-create-form" onSubmit={handleSubmit} noValidate>
              <div
                className="divide-y"
                style={{ borderColor: "var(--martis-border)" }}
              >
                {schema.fields.map((field) => (
                  <div
                    key={field.attribute}
                    className="grid grid-cols-3 gap-4 py-3"
                    style={{ borderColor: "var(--martis-border)" }}
                  >
                    <div>
                      <label
                        htmlFor={field.attribute}
                        className="block text-sm font-medium"
                        style={{ color: "var(--martis-text-muted)" }}
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
                        resourceKey={relatedResource}
                        context="create"
                      />
                    </div>
                  </div>
                ))}
              </div>
            </form>
          ) : (
            <div style={{ color: "var(--martis-text-muted)" }}>
              {tMsg("error_schema", "Failed to load schema.")}
            </div>
          )}
        </div>

        {/* Footer */}
        <div
          className="flex items-center justify-end gap-3 border-t px-6 py-4"
          style={{
            borderColor: "var(--martis-border)",
            backgroundColor: "var(--martis-surface)",
            borderRadius: "0 0 0.75rem 0.75rem",
          }}
        >
          <button
            type="button"
            onClick={onClose}
            className="inline-flex items-center gap-2 rounded-lg border px-4 py-2 text-sm font-medium"
            style={{
              backgroundColor: "var(--martis-input-bg)",
              borderColor: "var(--martis-border)",
              color: "var(--martis-text)",
            }}
          >
            {tAct("cancel")}
          </button>
          <button
            type="submit"
            form="inline-create-form"
            disabled={createMutation.isPending || schemaQuery.isLoading}
            className="inline-flex items-center gap-2 rounded-lg px-4 py-2 text-sm font-medium text-white disabled:opacity-50"
            style={{ backgroundColor: "var(--martis-accent)" }}
          >
            <Plus size={14} />
            {createMutation.isPending
              ? tAct("saving")
              : `${tAct("create")} ${schema?.singularLabel ?? ""}`}
          </button>
        </div>
      </div>
    </div>,
    document.body,
  )
}
