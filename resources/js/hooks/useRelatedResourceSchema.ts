import { useQuery } from '@tanstack/react-query'
import { api } from '@/lib/api'
import { apiPath, withQuery } from '@/lib/apiPath'

/**
 * The related resource's schema, as a relationship panel reads it.
 *
 * A panel takes its columns (and its labels, soft-delete toggle and inline
 * actions) from the related resource's schema endpoint. That endpoint is a
 * human surface, so it answers 404 for a resource whose `routable()` is
 * `false` — and a headless resource is exactly what a project registers as
 * a pure relation target. The panel then rendered rows with an Actions
 * column and nothing else.
 *
 * Naming the relationship the panel belongs to (`viaResource`,
 * `viaRelationship`) lets the endpoint serve a headless relation target:
 * the server checks that the named parent really declares that
 * relationship over this resource, and the resource's own `viewAny` still
 * gates the payload. Without the pair the endpoint is unchanged, so a page
 * context keeps getting the 404.
 */
export function relatedResourceSchemaPath(
  relatedResource: string,
  viaResource?: string | null,
  viaRelationship?: string | null,
): string {
  const path = apiPath`/api/resources/${relatedResource}/schema`

  if (!viaResource || !viaRelationship) {
    return path
  }

  return withQuery(path, `viaResource=${encodeURIComponent(viaResource)}&viaRelationship=${encodeURIComponent(viaRelationship)}`)
}

/**
 * Query the related resource's schema for a relationship panel. `T` is the
 * slice of the schema the caller reads.
 */
export function useRelatedResourceSchema<T>(
  relatedResource: string,
  viaResource?: string | null,
  viaRelationship?: string | null,
) {
  return useQuery({
    queryKey: ['schema', relatedResource, viaResource ?? null, viaRelationship ?? null],
    queryFn: ({ signal }) => api.get<{ data: T }>(
      relatedResourceSchemaPath(relatedResource, viaResource, viaRelationship),
      signal,
    ),
    enabled: !!relatedResource,
  })
}
