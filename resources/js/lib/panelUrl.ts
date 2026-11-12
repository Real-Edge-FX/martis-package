import { BASE_PATH } from '@/lib/config'

/**
 * A URL the server hands the SPA to open, kept to the panel itself: a path
 * under the Martis base path, on this origin. Returns the path and query as
 * the browser resolves them, or null for anything else.
 *
 * Never an absolute or protocol-relative URL, and never a path the browser
 * would rewrite out of the base path (a dot segment, an encoded one, a
 * backslash). What is returned is the path as the browser resolves it, so the
 * value that was checked is the value that is opened.
 *
 * Used for `sso_reauth_url` (a session the server ended) and `intended_url`
 * (the page asked for before a sign-in, v2.21.0).
 */
export function panelUrlTarget(url: unknown): string | null {
  if (typeof url !== 'string' || !url.startsWith(BASE_PATH + '/')) return null

  let resolved: URL
  try {
    resolved = new URL(url, window.location.origin)
  } catch {
    return null
  }
  if (resolved.origin !== window.location.origin || !resolved.pathname.startsWith(BASE_PATH + '/')) return null

  return resolved.pathname + resolved.search
}
