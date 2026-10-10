const skipped = (value: unknown): boolean => value === null || value === undefined || value === false || value === ''

function appendParam(query: URLSearchParams, key: string, value: unknown): void {
  if (skipped(value)) return

  if (Array.isArray(value)) {
    value.forEach((item) => appendParam(query, `${key}[]`, item))
    return
  }

  if (typeof value === 'object') {
    for (const [name, item] of Object.entries(value as Record<string, unknown>)) {
      appendParam(query, `${key}[${name}]`, item)
    }
    return
  }

  query.append(key, String(value))
}

/**
 * The URL a `visit` action answer loads (v1.39.6): the path with the
 * answer's `params` added to its query string. The query the path already
 * has is kept as written, the params follow it, and a `#fragment` stays at
 * the end. Null, `false` and `''` params are left out. Lists and nested maps
 * use the `key[]` / `key[name]` form PHP parses back into arrays. The path is
 * used as given (the page is loaded in full). Returns null without a path.
 */
export function actionVisitTarget(path: unknown, params: unknown): string | null {
  if (typeof path !== 'string' || path === '') return null

  const query = new URLSearchParams()
  if (params !== null && typeof params === 'object') {
    for (const [key, value] of Object.entries(params as Record<string, unknown>)) {
      appendParam(query, key, value)
    }
  }

  const hashAt = path.indexOf('#')
  const fragment = hashAt === -1 ? '' : path.slice(hashAt)
  const beforeFragment = hashAt === -1 ? path : path.slice(0, hashAt)
  const search = query.toString()
  if (search === '') return path

  const joiner = beforeFragment.includes('?') ? (beforeFragment.endsWith('?') || beforeFragment.endsWith('&') ? '' : '&') : '?'

  return `${beforeFragment}${joiner}${search}${fragment}`
}
