import { createContext, useContext } from 'react'
import { useParams } from 'react-router-dom'

/**
 * Context pointing to the "real parent" of the relationship panels rendered
 * inside a record that is not the one in the page URL:
 *
 * - a HasOne / MorphOne card provides its related record to the relationship
 *   fields it renders, so `team-members/1 > firstManagedProject (HasOne) >
 *   tasks (HasMany)` lists the tasks of the project, not of the team member;
 * - a record drawer provides the record it shows, since an action, a lens row
 *   or an index row can open the drawer of a record the URL does not name;
 * - a create surface (the create page, the create drawer, the inline-create
 *   modal) provides `id: null`: its record does not exist yet, so no panel
 *   inside reads the page's record or the record of a drawer behind it.
 *
 * Relationship panels read it through `useRelationParent()`. Consumer
 * components (a custom override, a Tool) reach the provider through
 * `@martis/runtime`.
 */
export interface NestedParent {
  resource: string
  /** `null` while the record does not exist yet (a create surface). */
  id: string | number | null
}

const NestedParentContext = createContext<NestedParent | null>(null)

export function NestedParentProvider({
  value,
  children,
}: {
  value: NestedParent
  children: React.ReactNode
}) {
  return <NestedParentContext.Provider value={value}>{children}</NestedParentContext.Provider>
}

export function useNestedParent(): NestedParent | null {
  return useContext(NestedParentContext)
}

/**
 * The record whose related records a relationship panel lists: the nearest
 * NestedParent when the panel renders inside a card, a drawer or a create
 * surface, else the record in the route of the detail or edit page
 * (`/resources/:resource/:id`). Every relationship panel resolves its parent
 * here, so none of them can fall back to the page record while nested. The
 * id is empty when there is no record yet: a create surface, or a page whose
 * route names none.
 */
export function useRelationParent(): { resource: string; id: string } {
  const nested = useNestedParent()
  const params = useParams<{ resource?: string; id?: string }>()

  if (nested) {
    return { resource: nested.resource, id: nested.id === null ? '' : String(nested.id) }
  }

  return { resource: params.resource ?? '', id: params.id ?? '' }
}
