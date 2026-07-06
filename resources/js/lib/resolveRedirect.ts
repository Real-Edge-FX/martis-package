import { recordHref } from '@/lib/recordHref'

/**
 * Resolves the target path after a create/update action, based on the
 * override's `redirectAfter` configuration.
 *
 * Predefined values: 'detail', 'index', 'edit', 'create', 'dashboard', 'stay'
 * Custom URLs: strings with {id} and {resource} placeholders
 *
 * @param redirectAfter  The configured redirect target (null = default 'detail')
 * @param resource       The resource URI key (e.g. "projects")
 * @param recordId       The ID of the created/updated record
 * @returns The resolved path, or null if 'stay' (no navigation)
 */
export function resolveRedirect(
  redirectAfter: string | null | undefined,
  resource: string,
  recordId: string | number,
): string | null {
  const target = redirectAfter ?? 'detail'

  switch (target) {
    case 'detail':
      return recordHref(resource, recordId)
    case 'index':
      return `/resources/${resource}`
    case 'edit':
      return `/resources/${resource}/${recordId}/edit`
    case 'create':
      return `/resources/${resource}/create`
    case 'dashboard':
      return '/'
    case 'stay':
      return null
    default:
      // Custom URL — replace placeholders
      return target
        .replace(/\{id\}/g, String(recordId))
        .replace(/\{resource\}/g, resource)
  }
}
