/**
 * `@tanstack/react-query` shim (v1.10.0+).
 *
 * Re-exports from `window.Martis.runtime.tanstackReactQuery`. The
 * consumer's vite alias maps `@tanstack/react-query` here so override
 * stubs that use `useQuery` / `useMutation` work without the
 * consumer running `npm install @tanstack/react-query` (the package
 * already bundles a single version of the library).
 */

const Q =
  typeof window !== 'undefined' &&
  window.Martis &&
  window.Martis.runtime &&
  window.Martis.runtime.tanstackReactQuery
    ? window.Martis.runtime.tanstackReactQuery
    : null

if (Q === null) {
  throw new Error(
    '[tanstack-react-query-shim] window.Martis.runtime.tanstackReactQuery not available — upgrade Martis to v1.10+ or install @tanstack/react-query directly.',
  )
}

export const useQuery = Q.useQuery
export const useMutation = Q.useMutation
export const useQueryClient = Q.useQueryClient
export const useInfiniteQuery = Q.useInfiniteQuery
export const useIsFetching = Q.useIsFetching
export const useIsMutating = Q.useIsMutating
export const useQueries = Q.useQueries
export const useSuspenseQuery = Q.useSuspenseQuery
export const QueryClient = Q.QueryClient
export const QueryClientProvider = Q.QueryClientProvider

export default Q
