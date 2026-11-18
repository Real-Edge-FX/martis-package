import { useQueryClient } from "@tanstack/react-query"
import { useNavigate } from "react-router"
import { routePath } from "@/lib/apiPath"
import { useRelatedResourceSchema } from "@/hooks/useRelatedResourceSchema"
import { componentRegistry } from "@/lib/componentRegistry"
import { useToast } from "@/contexts/ToastContext"
import type { OverrideProps, ResourceSchema } from "@/types"
import { MartisLoader } from "@/components/Loader"
import { recordHref } from "@/lib/recordHref"

interface ActionDrawerProps {
  type: "create" | "detail" | "update"
  resource: string
  recordId?: string | number
  onClose: () => void
  onSuccess: () => void
  /**
   * Optional hook that lets the parent swap the drawer contents without
   * navigating away from the current page. When defined, the detail drawer's
   * "Edit" button re-uses the same drawer shell (index stays mounted behind
   * the backdrop) instead of routing to `/edit` and leaving an empty page
   * underneath the overlay.
   */
  onSwitchTo?: (next: { type: "create" | "detail" | "update"; resource: string; recordId?: string | number }) => void
  /**
   * The relationship panel that opened the drawer, when it did. Naming it
   * lets the drawer read the schema of a headless (`routable(false)`)
   * related resource, which the bare schema endpoint answers with a 404.
   * Pass it only when `resource` is the panel's related resource.
   */
  viaResource?: string
  viaRelationship?: string
}

/**
 * Renders a create, detail, or update drawer for any resource,
 * triggered by an ActionResponse (openCreate / openDetail / openUpdate).
 */
export function ActionDrawer({ type, resource, recordId, onClose, onSuccess, onSwitchTo, viaResource, viaRelationship }: ActionDrawerProps) {
  const { addToast } = useToast()
  const navigate = useNavigate()
  const qc = useQueryClient()

  const schemaQuery = useRelatedResourceSchema<ResourceSchema>(resource, viaResource, viaRelationship)

  if (schemaQuery.isLoading) {
    return <MartisLoader />
  }

  const schema = schemaQuery.data?.data
  if (!schema) return null

  const componentKey =
    type === "create" ? "martis:drawer-create" :
    type === "update" ? "martis:drawer-update" :
    "martis:drawer-detail"

  const DrawerComponent = componentRegistry.resolve(componentKey)
  if (!DrawerComponent) return null

  const C = DrawerComponent as React.ComponentType<OverrideProps>

  // Pull the params the resource declared for this override (width,
  // expandedWidth, subtitle, showIcon, …). Without this, DrawerShell
  // falls back to its hard-coded defaults and renders at a different
  // size than the create drawer opened from the index toolbar — which
  // uses the same override params through OverrideResolver.
  const overrideParams = (schema.overrides?.[type]?.params ?? {}) as Record<string, unknown>

  const overrideProps: OverrideProps = {
    schema,
    resource,
    params: overrideParams,
    record: null,
    recordId: recordId != null ? String(recordId) : null,
    navigate: (to: string) => navigate(to),
    onClose,
    onCreated: () => {
      void qc.invalidateQueries({ queryKey: ["resources", resource] })
      addToast("success", schema.messages?.created ?? "Record created successfully.")
      onSuccess()
      onClose()
    },
    onUpdated: () => {
      void qc.invalidateQueries({ queryKey: ["resources", resource] })
      addToast("success", schema.messages?.updated ?? "Record updated successfully.")
      onSuccess()
      onClose()
    },
    onDeleted: () => {
      void qc.invalidateQueries({ queryKey: ["resources", resource] })
      onSuccess()
      onClose()
    },
    onEdit: (id) => {
      if (id == null) return
      if (onSwitchTo) {
        onSwitchTo({ type: "update", resource, recordId: id })
        return
      }
      navigate(routePath`/resources/${resource}/${id}/edit`)
    },
    onView: (id) => {
      if (onSwitchTo) {
        onSwitchTo({ type: "detail", resource, recordId: id })
        return
      }
      navigate(recordHref(resource, id))
    },
    addToast,
  }

  return <C {...overrideProps} />
}
