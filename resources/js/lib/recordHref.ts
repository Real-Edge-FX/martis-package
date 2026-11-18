import { config } from '@/lib/config'
import { routePath } from '@/lib/apiPath'
import { ownEntry } from '@/lib/ownEntry'

/**
 * Resolve the destination for a record of `uriKey`. Interpolates the
 * per-resource template from `config.resourceRecordUrls` (e.g. a headless
 * resource pointing at its owning Tool), else the default detail path.
 */
export function recordHref(uriKey: string, id: string | number): string {
  const template = ownEntry(config.resourceRecordUrls, uriKey)
  return template
    ? template.replace('{id}', encodeURIComponent(String(id)))
    : routePath`/resources/${uriKey}/${id}`
}

/**
 * Whether a record of `uriKey` has a page to link to. A routable resource has
 * its detail page; a headless one (`routable(false)`) has none, unless it
 * declares `recordUrl()` and so appears in `config.resourceRecordUrls`.
 *
 * `routable` is the schema's `routable` flag; a schema that does not carry it
 * (still loading, or an older server) counts as routable.
 */
export function hasRecordPage(uriKey: string, routable: boolean | undefined): boolean {
  return routable !== false || !!ownEntry(config.resourceRecordUrls, uriKey)
}
