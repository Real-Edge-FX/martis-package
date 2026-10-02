/**
 * What the server receives for an API path, resolved the way the browser's
 * fetch() resolves it (WHATWG URL): dot segments removed, `?` starting the
 * query. The client-side path traversal tests assert on this rather than on
 * the string the code built, because the rewrite happens in the URL parser.
 */
const ORIGIN = 'http://localhost/martis'

/** The URL fetch() sends for `path` (the one the code passed to `api.*`). */
export function resolveApiPath(path: string): URL {
  return new URL(`${ORIGIN}${path}`)
}

/** The path segments after `/martis/api/`, as the server receives them (still encoded). */
export function apiSegments(path: string): string[] {
  return resolveApiPath(path).pathname.replace(/^\/martis\/api\//, '').split('/')
}

/**
 * Hostile record ids: each must reach the server as ONE path segment. The
 * `_SEGMENT` form is what `apiPath` makes of the id (a slash is spelt `%252F`,
 * because Laravel decodes `%2F` before it matches a route); the `_ROUTE` form
 * is the id in a link of the SPA, which React Router decodes back (`%2F`).
 */
export const TRAVERSING_ID = '../users/5'
export const TRAVERSING_ID_SEGMENT = '..%252Fusers%252F5'
export const TRAVERSING_ID_ROUTE = '..%2Fusers%2F5'
/** The id React Router hands a page for `/resources/posts/..%2Fusers%2F5%3Femail%3Da%40b.c/edit`. */
export const QUERY_SMUGGLING_ID = '../users/5?email=a@b.c'
export const QUERY_SMUGGLING_ID_SEGMENT = '..%252Fusers%252F5%3Femail%3Da%40b.c'
export const QUERY_SMUGGLING_ID_ROUTE = '..%2Fusers%2F5%3Femail%3Da%40b.c'
