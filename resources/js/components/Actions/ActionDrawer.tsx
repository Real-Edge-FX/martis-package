import { useQuery, useQueryClient } from "@tanstack/react-query"
import { useNavigate } from "react-router-dom"
import { api } from "@/lib/api"
import { componentRegistry } from "@/lib/componentRegistry"
import { useToast } from "@/contexts/ToastContext"
import type { OverrideProps, ResourceSchema } from "@/types"
import { MartisLoader } from "@/components/Loader"

interface ActionDrawerProps {
  type: "create" | "detail" | "update"
  resource: string
  recordId?: string | number
  onClose: () => void
  onSuccess: () => void
}

/**
 * Renders a create, detail, or update drawer for any resource,
 * triggered by an ActionResponse (openCreate / openDetail / openUpdate).
 */
export function ActionDrawer({ type, resource, recordId, onClose, onSuccess }: ActionDrawerProps) {
  const { addToast } = useToast()
  const navigate = useNavigate()
  const qc = useQueryClient()

  const schemaQuery = useQuery({
    queryKey: ["schema", resource],
    queryFn: () => api.get<{ data: ResourceSchema }>(`/api/resources/${resource}/schema`),
  })

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

  const overrideProps: OverrideProps = {
    schema,
    resource,
    params: {},
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
      if (id != null) navigate(`/resources/${resource}/${id}/edit`)
    },
    onView: (id) => {
      navigate(`/resources/${resource}/${id}`)
    },
    addToast,
  }

  return <C {...overrideProps} />
}
