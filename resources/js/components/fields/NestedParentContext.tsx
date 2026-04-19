import { createContext, useContext } from 'react'

/**
 * Context pointing to the "real parent" when a relationship (HasMany,
 * HasOne::ofMany, Morph variants) is rendered *inside* another
 * relationship — e.g. `team-members/1 > firstManagedProject (HasOneThrough) >
 * tasks (HasMany)`. Without this context HasManyField reads
 * `window.location.pathname` to find the parent, which picks up the
 * team-member instead of the nested project.
 *
 * Parent fields provide their loaded record via `resource` + `id`; nested
 * fields consume it before falling back to the URL.
 */
export interface NestedParent {
  resource: string
  id: string | number
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
