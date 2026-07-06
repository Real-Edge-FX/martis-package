import { config } from '@/lib/config'

/**
 * Resolve the destination for a record of `uriKey`. Interpolates the
 * per-resource template from `config.resourceRecordUrls` (e.g. a headless
 * resource pointing at its owning Tool), else the default detail path.
 */
export function recordHref(uriKey: string, id: string | number): string {
  const template = config.resourceRecordUrls?.[uriKey]
  return template
    ? template.replace('{id}', encodeURIComponent(String(id)))
    : `/resources/${uriKey}/${id}`
}
