import { ApiError } from './apiError'

/**
 * Builds panel API paths from values that are not the SPA's own constants:
 * a route param, a query-string param, a record id, a relationship name, a
 * field attribute, a Tool, Action or Lens key.
 *
 * The browser resolves a request URL before it leaves: `..` and `.` segments
 * are removed, `?` starts the query string, `#` the fragment, and a
 * backslash is a slash. A value interpolated raw into
 * `/api/resources/${resource}/${id}` can therefore rewrite the request to
 * another endpoint of the same origin (`id = '../users/5'`) or append a
 * query string (`id = '5?email=x'`), and the request still carries the
 * session cookie and the XSRF token (client-side path traversal). React
 * Router decodes `%2F` and `%3F` in a route param, and `searchParams.get()`
 * decodes a query value, so a crafted link reaches those characters; so does
 * a record whose string key a less-trusted writer chose.
 *
 * `apiPath` is a tagged template: the literal text stays as written, and
 * every interpolated value is encoded as ONE path segment (or one query value
 * after a `?`).
 *
 *   api.get(apiPath`/api/resources/${resource}/${id}/replicate`)
 *
 * Append an already built query string with `withQuery()`, never by
 * interpolating it.
 *
 * Encoding the browser's way is not enough for a `/`. Laravel decodes the
 * path (`rawurldecode`) before it matches a route, so a key `5/force` sent as
 * `5%2Fforce` reaches `/resources/{resource}/5/force`: the force-delete route
 * of record 5 instead of an unknown record. A slash cannot be one segment on
 * the server, so a segment's slash is spelt `%252F`: the server decodes it to
 * the text `%2F`, which no route splits and no real key holds. A key with a
 * slash is as unaddressable as it always was (its request answers 404), and no
 * longer addresses anything else.
 *
 * That spelling is not injective on its own: the server decodes once, so a
 * value that already holds the text `%2F` (`a%2Fb`) arrives as the very text
 * a slash does (`a/b` is `a%252Fb` on the wire, and so is `a%2Fb`). A request
 * meant for one record would address the other. No encoding can tell them
 * apart, so a path segment holding a literal `%2F` (either case) is refused
 * with the `ApiError` (status 400) `request()` answers a dot segment with, and
 * nothing is sent: a record keyed that way is as unaddressable as one keyed
 * `..`. A query value and a path of the SPA router are not affected: both
 * decode once, so a plain `%2F` is injective there.
 */

/**
 * The URL parser's dot segments: `.` and `..`, each also spelt with `%2e`
 * in any case (`%2e`, `.%2e`, `%2e.`, `%2e%2e`).
 */
const DOT_SEGMENT = /^(?:\.|%2e){1,2}$/i

/** The text `%2F`, the one the `%252F` spelling of a slash collides with. */
const LITERAL_ENCODED_SLASH = /%2f/i

/**
 * A value that can fill a path segment. A missing one (a route param the
 * router has not resolved) becomes the text `undefined`, as in a template
 * literal: it names no endpoint, and the server answers 404.
 */
type PathValue = string | number | null | undefined

/**
 * One value as one segment of a URL: `/`, `?`, `#`, `%` and `\` are encoded,
 * and a value made of dots only is spelt `%2E` (`encodeURIComponent` leaves a
 * `.` alone). The URL parser still reads `%2E%2E` as a dot segment, so
 * `request()` refuses such a path (`hasDotSegment`): a record keyed `..` can
 * never redirect a request, and cannot be addressed either.
 */
function encodeSegment(value: PathValue): string {
  const encoded = encodeURIComponent(String(value))
  return DOT_SEGMENT.test(encoded) ? encoded.replace(/\./g, '%2E') : encoded
}

/**
 * One value as one segment of an API path, as the server will read it: `?`,
 * `#`, `%` and `\` are encoded, and a slash is spelt `%252F` because Laravel
 * decodes the path before it routes (a plain `%2F` would split the route).
 * Throws an `ApiError` (400) for a value that holds a literal `%2F`, which
 * would collide with a slash.
 */
export function pathSegment(value: PathValue): string {
  if (LITERAL_ENCODED_SLASH.test(String(value))) {
    throw new ApiError(400, 'Request failed', [])
  }

  return encodeSegment(value).replace(/%2F/g, '%252F')
}

/**
 * Tag for a panel API path: every interpolated value is encoded as one path
 * segment, or as one query value once the literal text has opened the query
 * string (`?q=${term}`: a slash there stays a plain `%2F`, as the server
 * decodes a query value once).
 */
export function apiPath(strings: TemplateStringsArray, ...values: ReadonlyArray<PathValue>): string {
  let path = strings[0] ?? ''
  values.forEach((value, index) => {
    path += (path.includes('?') ? encodeSegment(value) : pathSegment(value)) + (strings[index + 1] ?? '')
  })
  return path
}

/**
 * Tag for a path of the SPA's own router (`/resources/${resource}/${id}/edit`):
 * a record keyed `a/b` or `5?x` stays one segment of the link, and React
 * Router decodes it back when the page reads its params (the SPA's router is
 * the browser's, not Laravel's: a plain `%2F` is right here).
 */
export function routePath(strings: TemplateStringsArray, ...values: ReadonlyArray<PathValue>): string {
  let path = strings[0] ?? ''
  values.forEach((value, index) => {
    path += encodeSegment(value) + (strings[index + 1] ?? '')
  })
  return path
}

/** `url` with `query` appended to the query string it may already have. */
export function withQuery(url: string, query: string): string {
  if (query === '') return url

  return `${url}${url.includes('?') ? '&' : '?'}${query}`
}

/**
 * Whether the path part of `path` (what comes before `?` or `#`) holds a dot
 * segment, as the URL parser reads it: a segment of `.` or `..`, spelt with
 * `%2e` or not, with a backslash taken as a slash and tabs and newlines
 * dropped. A panel API path never holds one on purpose.
 */
export function hasDotSegment(path: string): boolean {
  const end = path.search(/[?#]/)
  const pathPart = (end === -1 ? path : path.slice(0, end)).replace(/[\t\n\r]/g, '')

  return pathPart.split(/[/\\]/).some((segment) => DOT_SEGMENT.test(segment))
}
