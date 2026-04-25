import { useState, useEffect, useCallback } from "react"
import { createPortal } from "react-dom"
import { useQuery, useMutation } from "@tanstack/react-query"
import { api, ApiError, hasFileValues } from "@/lib/api"
import { FieldInput } from "@/components/fields/FieldRenderer"
import { useToast } from "@/contexts/ToastContext"
import { useTranslation } from "react-i18next"
import { XIcon, PlusIcon } from "@phosphor-icons/react"
import type { FieldDefinition } from "@/types"
import { ResourceIcon } from "@/components/ResourceIcon"
import { useModalHistoryLock } from "@/lib/historyLock"

/** Modal size — maps to a max-width in pixels so the panel scales
 *  beyond the 480px default of `.martis-modal-surface`. */
const MODAL_MAX_WIDTH: Record<string, string> = {
  sm: "384px",
  md: "448px",
  lg: "512px",
  xl: "576px",
  "2xl": "672px",
  "3xl": "768px",
  "4xl": "896px",
  "5xl": "1024px",
  "6xl": "1152px",
  "7xl": "1280px",
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
  /** When true, show the resource icon from the schema (or the override) in the modal header */
  showResourceIcon?: boolean
  /** Override Phosphor icon name (replaces schema icon when showResourceIcon is true) */
  resourceIconOverride?: string | null
  /** Color for the resource icon in the modal header */
  resourceIconColor?: string | null
  /** Subtitle text to show in the modal header */
  resourceSubtitle?: string | boolean | null
}

export function InlineCreateModal({
  relatedResource,
  open,
  onClose,
  onCreated,
  modalSize = "2xl",
  showResourceIcon = false,
  resourceIconOverride,
  resourceIconColor,
  resourceSubtitle,
}: InlineCreateModalProps) {
  const { addToast } = useToast()
  const { t: tAct } = useTranslation("actions")
  const { t: tMsg } = useTranslation("messages")

  useModalHistoryLock(open)

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
          icon?: string
          iconColor?: string | null
          subtitle?: string | null
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
        const byField = err.errorsByField()
        // Get visible field attributes from schema
        const visibleFields = new Set(
          schemaQuery.data?.data?.fields?.map((f) => f.attribute) ?? [],
        )
        // Separate mapped (visible) vs unmapped (invisible field) errors
        const mapped: Record<string, string> = {}
        const unmapped: string[] = []
        for (const [field, msg] of Object.entries(byField)) {
          if (visibleFields.has(field)) {
            mapped[field] = msg
          } else {
            unmapped.push(msg)
          }
        }
        // Show unmapped field errors as a general message
        if (unmapped.length > 0) {
          mapped._general = unmapped.join(". ")
        }
        setErrors(mapped)
        addToast("error", err.message || tMsg("error_create"))
      } else if (err instanceof ApiError) {
        setErrors({ _general: err.message || tMsg("error_create") })
        addToast("error", err.message || tMsg("error_create"))
      } else {
        setErrors({ _general: tMsg("error_create") })
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
  const maxWidth = MODAL_MAX_WIDTH[modalSize] ?? MODAL_MAX_WIDTH["2xl"]

  return createPortal(
    <div
      className="martis-modal-scrim"
      style={{ zIndex: 9999 }}
      onKeyDown={(e) => { if (e.key === "Enter") e.stopPropagation() }}
      onClick={onClose}
    >
      <div
        role="dialog"
        aria-modal="true"
        className="martis-modal-surface"
        style={{ maxWidth }}
        onClick={(e) => e.stopPropagation()}
      >
        <div className="martis-modal-head">
          <div className="flex items-center gap-3">
            {showResourceIcon && (
              <ResourceIcon
                iconName={resourceIconOverride ?? schema?.icon ?? "database"}
                size={22}
                color={resourceIconColor ?? "var(--martis-accent)"}
              />
            )}
            <div className="flex flex-col">
              <h3 className="martis-modal-head-title">
                {tAct("create")} {schema?.singularLabel ?? relatedResource}
              </h3>
              {resourceSubtitle && (
                <span
                  className="text-sm"
                  style={{ color: "var(--martis-text-muted)" }}
                >
                  {typeof resourceSubtitle === "string" ? resourceSubtitle : schema?.subtitle}
                </span>
              )}
            </div>
          </div>
          <button
            type="button"
            onClick={onClose}
            className="martis-modal-close"
            aria-label={tAct("cancel")}
          >
            <XIcon size={16} />
          </button>
        </div>

        <div className="martis-modal-body">
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
                          <span className="ml-1" aria-hidden="true" style={{ color: "var(--martis-danger)" }}>
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
                {errors._general && (
                  <div className="py-3">
                    <small style={{ color: "var(--martis-danger)" }}>{errors._general}</small>
                  </div>
                )}
              </div>
            </form>
          ) : (
            <div style={{ color: "var(--martis-text-muted)" }}>
              {tMsg("error_schema", "Failed to load schema.")}
            </div>
          )}
        </div>

        <div className="martis-modal-foot">
          <button type="button" onClick={onClose} className="martis-btn-secondary">
            {tAct("cancel")}
          </button>
          <button
            type="submit"
            form="inline-create-form"
            disabled={createMutation.isPending || schemaQuery.isLoading}
            className="martis-btn-primary"
          >
            <PlusIcon size={14} />
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
