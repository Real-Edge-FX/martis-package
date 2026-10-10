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

import type { QueryClient } from '@tanstack/react-query'

const BASENAME = '/martis'

/**
 * The endpoint kinds that store a record nested under a parent
 * (`POST /api/resources/{resource}/{id}/{kind}/{relationship}`). The create
 * page reads `viaRelationshipType` from the link's query string and puts it
 * into that path, so it only accepts these (a many-to-many relationship
 * attaches, it does not store).
 */
export const NESTED_STORE_KINDS = ['has-many', 'has-one', 'morph-many', 'morph-one'] as const

export type NestedStoreKind = typeof NESTED_STORE_KINDS[number]

/** The nested-store kind `value` names, or `null` when it names none. */
export function nestedStoreKind(value: string | null | undefined): NestedStoreKind | null {
  return NESTED_STORE_KINDS.find((kind) => kind === value) ?? null
}

/**
 * Invalidate the parent's relationship panel after a record created or
 * edited from it (`['has-many' | 'has-one' | 'morph-many' | 'morph-one',
 * parentResource, parentId, relationship]`, the key the panel queries with).
 * The SPA navigates back client-side, and the query client keeps a list for
 * 30 s, so without this the panel shows the list it had before the save.
 */
export function invalidateRelationPanel(
  qc: QueryClient,
  kind: NestedStoreKind,
  parentResource: string,
  parentId: string | number,
  relationship: string,
): Promise<void> {
  return qc.invalidateQueries({ queryKey: [kind, parentResource, parentId, relationship] })
}

export interface ViaParamsInput {
  parentResource: string
  parentId: string | number | null | undefined
  relationship: string
  relationshipType: NestedStoreKind
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
