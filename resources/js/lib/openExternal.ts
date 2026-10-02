import { safeNavigationUrl } from './safeUrl'

/**
 * Open an external URL in a new tab, as an external menu link does
 * (`target="_blank" rel="noreferrer"`), so the panel stays open.
 *
 * Only an `http(s)` URL (or a same-origin path) opens: the URL comes from an
 * action answer or the navigation config, which an application may build
 * from stored data, and a `javascript:` / `data:` value must not reach
 * `window.open`. Anything else is refused with a console error, as the
 * `visit` answer of an action does.
 */
export function openExternal(url: string): void {
  const safe = safeNavigationUrl(url)
  if (safe === null) {
    console.error('[martis] refused to open a URL that is not http(s) or a path', url)
    return
  }

  window.open(safe, '_blank', 'noopener,noreferrer')
}
