import { useQuery } from '@tanstack/react-query'
import { api } from '@/lib/api'
import type { FieldDefinition } from '@/types'

export interface UseToolFieldsResult {
  fields: FieldDefinition[]
  isLoading: boolean
  error: unknown
}

/**
 * Fetch a Tool's field definitions (Mode B) from
 * `GET /api/tools/{toolKey}/fields`, the authz-gated endpoint that
 * serializes `Tool::fields()` via `Field::toArray()`. Returns the same
 * FieldDefinition shape the Resource/Action forms consume, ready to feed
 * into `useMartisForm({ fields })` / `<FieldsForm>`. Disabled until a
 * non-empty toolKey is supplied.
 */
export function useToolFields(toolKey: string): UseToolFieldsResult {
  const { data, isLoading, error } = useQuery({
    queryKey: ['martis', 'tool-fields', toolKey],
    queryFn: () => api.get<{ fields: FieldDefinition[] }>(`/api/tools/${toolKey}/fields`),
    enabled: Boolean(toolKey),
  })
  return { fields: data?.fields ?? [], isLoading, error }
}
