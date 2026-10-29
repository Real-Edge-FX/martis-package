import { api, ApiError } from '@/lib/api'
import { apiPath, withQuery } from '@/lib/apiPath'
import type { ActionResponsePayload } from './actionResponse'

/** `viaResource` / `viaResourceId` / `viaRelationship` of a panel's run. */
export interface ActionRequestVia {
  viaResource: string
  viaResourceId: string | number
  viaRelationship: string
}

/**
 * The endpoint an action runs through. A lens's actions live under its own
 * routes: an action only the lens declares is unknown to the resource's.
 */
export function actionRunUrl(resource: string, action: string, lens?: string | null): string {
  return lens
    ? apiPath`/api/resources/${resource}/lenses/${lens}/actions/${action}`
    : apiPath`/api/resources/${resource}/actions/${action}`
}

/**
 * Post an action run. The one request every runner sends (the action modal
 * and `useRunAction()`), so the body cannot drift between them.
 */
export function postAction(
  url: string,
  body: {
    resources: Array<string | number>
    fields?: Record<string, unknown>
    dryRun?: boolean
    via?: ActionRequestVia
  },
) {
  return api.post<{ data: ActionResponsePayload & { preview?: unknown } }>(url, {
    resources: body.resources,
    fields: body.fields ?? {},
    dryRun: body.dryRun ?? false,
    ...(body.via ?? {}),
  })
}

/**
 * A 422's errors per field, the `fields.` prefix of the request body
 * stripped so the key is the field's attribute; the first message of a
 * field wins. Empty for any other failure.
 */
export function mapActionErrors(err: unknown): Record<string, string> {
  const mapped: Record<string, string> = {}
  if (err instanceof ApiError && err.errors && err.errors.length > 0) {
    for (const e of err.errors) {
      const fieldKey = e.field.replace(/^fields\./, '')
      if (!mapped[fieldKey]) mapped[fieldKey] = e.message
    }
  }
  return mapped
}

/**
 * The fields endpoint of an action. When the modal runs on exactly one
 * record the request names it (`resourceId`, with the panel's `via` params
 * when it runs through a relationship), so the server resolves the fields
 * with that record in hand, as Nova's `GET .../actions?resourceId=` does.
 * No record (bulk run, standalone action): the plain endpoint.
 */
export function actionFieldsUrl(url: string, recordId?: string | number, via?: ActionRequestVia | null): string {
  if (recordId === undefined) return `${url}/fields`
  const query = new URLSearchParams({ resourceId: String(recordId) })
  if (via) {
    query.set('viaResource', via.viaResource)
    query.set('viaResourceId', String(via.viaResourceId))
    query.set('viaRelationship', via.viaRelationship)
  }
  return withQuery(`${url}/fields`, query.toString())
}
