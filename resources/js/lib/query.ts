import { QueryClient, MutationCache } from '@tanstack/react-query'
import { ApiError } from '@/lib/api'

/**
 * Refresh the navigation count badges after any successful write.
 *
 * Resources invalidate their own `['resources', <uriKey>]` query at 34+
 * call sites already; piggy-backing on the global mutation cache keeps
 * the sidebar/topnav counts in sync without having to update each site.
 */
const mutationCache = new MutationCache({
  onSuccess: () => {
    void queryClient.invalidateQueries({ queryKey: ['navigation'] })
  },
})

export const queryClient = new QueryClient({
  mutationCache,
  defaultOptions: {
    queries: {
      staleTime: 1000 * 30,
      retry: (failureCount, error) => {
        if (error instanceof ApiError && error.status < 500) return false
        return failureCount < 2
      },
    },
  },
})

