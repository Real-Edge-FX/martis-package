import { ApiError } from '@/lib/api'

/**
 * The toast of a failed delete: the message the API answered (the reason a
 * hook gave with a `UserFacingException`, or the generic delete error the
 * server keeps for any internal failure), or `fallback` for anything that
 * is not an API answer (a network failure).
 */
export function deleteErrorMessage(error: unknown, fallback: string): string {
  return error instanceof ApiError && error.message ? error.message : fallback
}
