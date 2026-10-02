/**
 * Guard for URLs the SPA hands to the browser as a navigation target: a
 * server-given redirect, a download link, a notification action, a link a
 * stored document carries. A `javascript:` URL assigned to `location.href`
 * (or clicked as a link) runs script in the panel origin with the viewer's
 * session, and `data:` / `vbscript:` / `file:` targets are no better, so
 * only the schemes a person expects to leave the page through pass.
 *
 * The browser's own URL parser decides the scheme, the same parser that
 * later follows the value: it strips the tabs, newlines and leading control
 * characters a pattern match would miss (`java\tscript:`), and resolves a
 * relative value against the page. That is what makes a relative path and a
 * scheme-relative `//host` URL come out as `http:` / `https:`.
 *
 * Returns the value unchanged when it is allowed, else `null`, so a caller
 * refuses with a console error (as the `visit` branch of an action answer
 * does) and never navigates to a value it could not vouch for.
 */

export interface SafeUrlOptions {
  /** Also allow `blob:` URLs (a download the page itself built). */
  blob?: boolean
  /** Also allow `mailto:` and `tel:` (a contact link, handed to the OS). */
  contact?: boolean
}

/** The protocols of a URL a person follows to another page: `http:` and `https:`. */
const WEB_PROTOCOLS = new Set(['http:', 'https:'])

/** The protocol the parser reads from `raw` (resolved against the page), or null when it is not a URL. */
export function urlProtocol(raw: string): string | null {
  const base = typeof window !== 'undefined' ? (window.location?.href ?? undefined) : undefined

  try {
    return new URL(raw, base).protocol.toLowerCase()
  } catch {
    return null
  }
}

/**
 * `raw` when it is an `http:` / `https:` URL or a path that resolves to one
 * (same-origin relative, `//host`), else `null`. See {@link SafeUrlOptions}
 * for the other schemes a caller may opt into.
 */
export function safeNavigationUrl(raw: unknown, options: SafeUrlOptions = {}): string | null {
  if (typeof raw !== 'string' || raw.trim() === '') return null

  const protocol = urlProtocol(raw)
  if (protocol === null) return null
  if (WEB_PROTOCOLS.has(protocol)) return raw
  if (options.blob === true && protocol === 'blob:') return raw
  if (options.contact === true && (protocol === 'mailto:' || protocol === 'tel:')) return raw

  return null
}

/** Boolean form of {@link safeNavigationUrl}. */
export function isSafeNavigationUrl(raw: unknown, options: SafeUrlOptions = {}): boolean {
  return safeNavigationUrl(raw, options) !== null
}

/**
 * The value for an `href` attribute: the URL itself when it is a safe link
 * target (web, relative, `mailto:` / `tel:`), else `undefined`, so React
 * renders an anchor without an `href`, which goes nowhere.
 */
export function safeHref(raw: string | null | undefined): string | undefined {
  return safeNavigationUrl(raw, { contact: true }) ?? undefined
}
