/** Longest prefill value the page forwards (the server ignores a longer one). */
export const PREFILL_MAX_LENGTH = 255

/**
 * The create page's prefill: the query keys the resource declared with
 * `acceptsPrefill()` that the link carries, as string values of at most
 * `PREFILL_MAX_LENGTH` characters. Any other key stays in the URL and is
 * never forwarded to the schema request.
 */
export function collectPrefill(keys: readonly string[] | undefined, searchParams: URLSearchParams): Record<string, string> {
  const prefill: Record<string, string> = {}
  for (const key of keys ?? []) {
    const value = searchParams.get(key)
    if (value !== null && value !== '' && value.length <= PREFILL_MAX_LENGTH) prefill[key] = value
  }
  return prefill
}

/** The schema request's query string for a prefill: `prefill[key]=value`. */
export function prefillQueryString(prefill: Record<string, string>): string {
  const params = new URLSearchParams()
  for (const [key, value] of Object.entries(prefill)) params.set(`prefill[${key}]`, value)
  return params.toString()
}
