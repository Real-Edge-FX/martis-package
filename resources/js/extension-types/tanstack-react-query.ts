/**
 * Type entry for the `@tanstack/react-query` shim
 * (`stubs/extensions/tanstack-react-query-shim.mjs.stub`).
 * `npm run build:types` bundles it into
 * `tanstack-react-query-shim.d.mts.stub`: the names the shim exports, and
 * the host's module namespace as the default export.
 */
import * as TanstackReactQuery from '@tanstack/react-query'

export {
  useQuery,
  useMutation,
  useQueryClient,
  useInfiniteQuery,
  useIsFetching,
  useIsMutating,
  useQueries,
  useSuspenseQuery,
  QueryClient,
  QueryClientProvider,
} from '@tanstack/react-query'

export default TanstackReactQuery
