/**
 * Guard for SPA navigation targets that come from outside the app's own
 * code (a query parameter, a configured menu URL, a notification payload).
 *
 * React Router 6 hands a string that starts with `/` straight to the
 * History API, and the browser's URL parser then reads `//host`, `/\host`
 * (backslash is a slash in http(s) URLs) and `/<tab>/host` (tabs and
 * newlines are stripped) as protocol-relative URLs pointing at another
 * origin. GHSA-wrjc-x8rr-h8h6 is that class of open redirect, fixed only in
 * React Router 7, so Martis filters these values itself before calling
 * `navigate()` or rendering a `<Link to>`.
 *
 * Returns the path unchanged when it is a same-origin absolute path, else
 * `null`, so callers fall back to their default destination.
 */
export function safeInternalPath(raw: string | null | undefined): string | null {
  if (typeof raw !== 'string' || raw === '') return null
  if (raw[0] !== '/') return null
  if (raw[1] === '/' || raw.includes('\\')) return null
  if (/[\u0000-\u001F\u007F]/.test(raw)) return null

  // Last line of defence: let the URL parser decide which origin the value
  // resolves to, with a placeholder base.
  const base = 'http://martis.invalid'
  try {
    if (new URL(raw, base).origin !== base) return null
  } catch {
    return null
  }

  return raw
}

/** Boolean form of {@link safeInternalPath}. */
export function isSafeInternalPath(raw: string | null | undefined): boolean {
  return safeInternalPath(raw) !== null
}
