import type { RepeaterRowScope } from '@/components/fields/types'

/**
 * Where a relation picker (`BelongsTo`, `MorphTo`, `Tag`) loads its options.
 *
 * The relatable endpoint reads the field from the form its URL names:
 * `/api/resources/{resource}/{id}/relatable/{attribute}` reads the update
 * form of record `{id}`, and `_` (or an id that names no record) the create
 * forms. The page's route only fills what the input does not say, and only
 * for the resource the route belongs to: its id names a record of that
 * resource, so another resource (a create form nested in the page, such as
 * the inline-create modal) never receives it. An Action's fields and a
 * relationship's pivot fields are read from the Action and the panel
 * instead, and a field of a Repeater row from that row, which the request
 * names in its query string.
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
  /** Base path of the pivot fields the input renders in (attach or pivot edit modal). */
  pivotEndpoint?: string
  /** The Repeater row the input renders in. */
  repeaterRow?: RepeaterRowScope
}

export interface RelatableRoute {
  resource?: string
  id?: string
}

/**
 * URL of the relatable request, or `null` when there is no resource to
 * scope it to; the caller then uses the context-free form
 * `/api/resources/_/_/relatable/{attribute}?related_resource=...`. The URL
 * carries a query string when the input renders in a Repeater row, so add
 * the picker's own parameters with `withQuery()`.
 */
export function relatableUrl(attribute: string, scope: RelatableScope, route: RelatableRoute): string | null {
  const path = relatablePath(attribute, scope, route)
  if (path === null || !scope.repeaterRow) return path

  return withQuery(path, repeaterRowQuery(scope.repeaterRow))
}

function relatablePath(attribute: string, scope: RelatableScope, route: RelatableRoute): string | null {
  const base = scope.actionEndpoint ?? scope.pivotEndpoint
  if (base) {
    return `${base}/relatable/${attribute}`
  }

  const resource = scope.resourceKey ?? route.resource
  if (!resource) return null

  const routeId = resource === route.resource ? route.id : undefined
  const id = scope.recordId != null
    ? String(scope.recordId)
    : scope.context === 'create' ? '_' : (routeId ?? '_')

  return `/api/resources/${resource}/${id}/relatable/${attribute}`
}

/** The query string that names a Repeater row to the server: `repeater=...&repeatable=...`. */
export function repeaterRowQuery(row: RepeaterRowScope): string {
  return new URLSearchParams({ repeater: row.repeater, repeatable: row.repeatable }).toString()
}

/** `url` with `query` appended to the query string it may already have. */
export function withQuery(url: string, query: string): string {
  if (query === '') return url

  return `${url}${url.includes('?') ? '&' : '?'}${query}`
}
