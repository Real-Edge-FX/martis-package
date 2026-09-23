/**
 * Where a relation picker (`BelongsTo`, `MorphTo`, `Tag`) loads its options.
 *
 * The relatable endpoint reads the field from the form its URL names:
 * `/api/resources/{resource}/{id}/relatable/{attribute}` reads the update
 * form of record `{id}`, and `_` (or an id that names no record) the create
 * forms. The page's route only fills what the input does not say, and only
 * for the resource the route belongs to: its id names a record of that
 * resource, so another resource (a create form nested in the page, such as
 * the inline-create modal) never receives it.
 */

export interface RelatableScope {
  /** Resource the form belongs to; defaults to the route's resource. */
  resourceKey?: string
  /** Record the form edits; wins over the route. */
  recordId?: string | number
  /** A create form never takes a record id from the route. */
  context?: 'create' | 'update'
  /** Base path of the Action whose fields the input renders in. */
  actionEndpoint?: string
}

export interface RelatableRoute {
  resource?: string
  id?: string
}

/**
 * Path of the relatable request (no query string), or `null` when there is
 * no resource to scope it to; the caller then uses the context-free form
 * `/api/resources/_/_/relatable/{attribute}?related_resource=...`.
 */
export function relatablePath(attribute: string, scope: RelatableScope, route: RelatableRoute): string | null {
  if (scope.actionEndpoint) {
    return `${scope.actionEndpoint}/relatable/${attribute}`
  }

  const resource = scope.resourceKey ?? route.resource
  if (!resource) return null

  const routeId = resource === route.resource ? route.id : undefined
  const id = scope.recordId != null
    ? String(scope.recordId)
    : scope.context === 'create' ? '_' : (routeId ?? '_')

  return `/api/resources/${resource}/${id}/relatable/${attribute}`
}
