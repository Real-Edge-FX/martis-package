import { createContext, useContext } from 'react'
import type { OverrideProps } from '@/types'

/**
 * React context that carries the live `OverrideProps` payload Martis
 * hands every override component (drawer create/update/detail, custom
 * resource view, etc.). The bundled drawer / page wrappers wrap their
 * children with this provider so deeply-nested components can pull
 * the same payload without prop-drilling.
 *
 * Usage in a custom override:
 *
 *     export function MyDrawerCreate(props: OverrideProps) {
 *       return (
 *         <OverridePropsProvider value={props}>
 *           <MyHeader />
 *           <MyForm />
 *         </OverridePropsProvider>
 *       )
 *     }
 *
 *     // ...later, in MyHeader (no props passed):
 *     function MyHeader() {
 *       const { schema, onClose } = useOverrideProps()
 *       return <h2>{schema.singularLabel}</h2>
 *     }
 *
 * The provider is opt-in — overrides that don't need cross-component
 * access can keep passing `props` through manually as before.
 */
const OverridePropsContext = createContext<OverrideProps | null>(null)

export const OverridePropsProvider = OverridePropsContext.Provider

/**
 * Read the override payload from context. Throws when called outside
 * an `<OverridePropsProvider>` so the failure is loud during development
 * — silently returning `null` would hide a wiring bug behind subtle
 * `undefined` field accesses.
 */
export function useOverrideProps(): OverrideProps {
  const ctx = useContext(OverridePropsContext)
  if (ctx === null) {
    throw new Error(
      'useOverrideProps() must be used inside <OverridePropsProvider>. ' +
      'Wrap your custom override component with the provider before reading props from nested components.',
    )
  }
  return ctx
}

/**
 * Non-throwing variant — returns `null` outside the provider. Useful
 * when an override component is shared between contexts where the
 * provider may or may not be present.
 */
export function useOverridePropsOptional(): OverrideProps | null {
  return useContext(OverridePropsContext)
}
