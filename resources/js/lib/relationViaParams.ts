/**
 * Shared builder for the relation-creation query string used by HasMany,
 * HasOne, MorphMany, MorphOne and MorphToMany "Criar" buttons.
 *
 * The five fields used to inline their own string-concatenation logic. That
 * meant each copy drifted independently (some swallowed `undefined` with
 * `??`, others silently emitted the literal `"undefined"` in the URL, and
 * the defaults weren't consistent). Centralising here keeps the URL contract
 * in one place and makes it obvious which params are essential, which are
 * inferable, and which are cosmetic.
 *
 * Conventions:
 * - Missing required context (no parent id available) returns `null` — the
 *   caller MUST hide the create affordance instead of rendering a URL that
 *   would POST to `/api/.../undefined/...` and 404.
 * - `redirectMode` is only emitted when it differs from the backend default
 *   (`parent`). Keeps the URL short for the common case.
 * - `from` strips the React Router basename before encoding (the router
 *   prepends it on `navigate`, which would otherwise double to `/martis/martis/...`).
 */

const BASENAME = '/martis'

export interface ViaParamsInput {
  parentResource: string
  parentId: string | number | null | undefined
  relationship: string
  relationshipType: 'has-many' | 'has-one' | 'morph-many' | 'morph-one' | 'morph-to-many'
  /** When it matches the backend default `parent`, the param is omitted. */
  redirectMode?: string | null
}

/**
 * Build the `?viaResource=...&viaResourceId=...&...&from=...` suffix.
 *
 * Returns `null` when the parent id is missing — the caller should treat
 * that as "can't create from here" and not render the button.
 */
export function buildViaParams(input: ViaParamsInput): string | null {
  const { parentResource, parentId, relationship, relationshipType } = input
  if (parentResource === '' || parentId === null || parentId === undefined || parentId === '') {
    return null
  }
  const parts = [
    `viaResource=${encodeURIComponent(parentResource)}`,
    `viaResourceId=${encodeURIComponent(String(parentId))}`,
    `viaRelationship=${encodeURIComponent(relationship)}`,
    `viaRelationshipType=${relationshipType}`,
  ]
  if (input.redirectMode && input.redirectMode !== 'parent') {
    parts.push(`redirectMode=${encodeURIComponent(input.redirectMode)}`)
  }
  const fromRaw = (window.location.pathname + window.location.search)
    .replace(new RegExp(`^${BASENAME}(?=/|$)`), '') || '/'
  parts.push(`from=${encodeURIComponent(fromRaw)}`)
  return `?${parts.join('&')}`
}

/**
 * Read the current pathname and return `{ resource, id }` identifying the
 * resource whose detail page we are on. Returns empty strings when there is
 * no such resource in the URL (e.g. we are on an index or the dashboard).
 */
export function readPathParent(): { resource: string; id: string } {
  const parts = window.location.pathname.split('/')
  const idx = parts.indexOf('resources')
  if (idx < 0) return { resource: '', id: '' }
  return {
    resource: parts[idx + 1] ?? '',
    id: parts[idx + 2] ?? '',
  }
}
