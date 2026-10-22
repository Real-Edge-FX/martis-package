import { martisEventBus } from '@/lib/eventBus'
import { safeInternalPath } from '@/lib/safeInternalPath'
import { safeNavigationUrl } from '@/lib/safeUrl'

/** The answer of an action run: `ActionResponse::jsonSerialize()`, `{ type, data }`. */
export interface ActionResponsePayload {
  type?: string
  data?: Record<string, unknown>
}

/** What the component that ran an action lends the dispatcher. */
export interface ActionResponseContext {
  t: (key: string) => string
  addToast: (type: 'success' | 'error' | 'warning', message: string, options?: { link?: { href: string; label: string } }) => void
  /** SPA navigation below the Martis base path (React Router's navigate()). */
  navigate: (to: string) => void
  /** Close the action's own modal. */
  hide: () => void
  /** Refresh what the action may have changed (the caller's onSuccess). */
  refresh: () => void
  /** Show a component for a `modal` answer, false when none is registered; null outside the shell. */
  showModal: ((component: string, data: Record<string, unknown>, onClose: () => void) => boolean) | null
  onOpenCreate?: (resource: string) => void
  onOpenDetail?: (resource: string, recordId: string | number) => void
  onOpenUpdate?: (resource: string, recordId: string | number) => void
  /** The tab opened on the click of an `opensInNewTab()` action
   *  ({@link openActionTab}): an `openInNewTab` answer sends it to the URL,
   *  any other answer closes it. `null` when the browser blocked it. Left
   *  out when the action does not declare `opensInNewTab()`. */
  tab?: Window | null
}

/**
 * Open the blank tab an `opensInNewTab()` action will send to its URL. Call
 * it in the click handler that runs the action: a tab opened only after the
 * request returns is no longer tied to a click, and the browser blocks it.
 * Returns null when the browser blocked this one too.
 *
 * The tab's `opener` is cut at once, as `rel="noopener"` would, so the page
 * it loads cannot reach back into the panel.
 */
export function openActionTab(): Window | null {
  const tab = window.open('', '_blank')
  if (tab) tab.opener = null
  return tab
}

/** Close the blank tab of a run that did not answer `openInNewTab`. */
export function closeActionTab(tab: Window | null | undefined): void {
  if (tab && !tab.closed) tab.close()
}

/**
 * Send an `openInNewTab` answer to its tab: the one opened on the click when
 * there is one, else a new one. A blocked tab leaves a warning with a link
 * to the page, since following a link is a fresh click the browser lets
 * through. Only an `http(s)` URL or a same-origin path opens; anything else
 * is refused with a console error.
 */
function openAnswerInNewTab(url: string, ctx: ActionResponseContext): void {
  const safe = safeNavigationUrl(url)
  if (safe === null) {
    closeActionTab(ctx.tab)
    console.error('[martis] action response: refused to open a URL that is not http(s) or a path', url)
    return
  }

  // No tab was opened on the click: open one now, the same way, so a
  // blocked tab shows as null and the opener is cut before the page loads.
  const tab = ctx.tab === undefined ? openActionTab() : ctx.tab
  if (tab !== null && !tab.closed) {
    tab.location.replace(safe)
    return
  }

  ctx.addToast('warning', ctx.t('new_tab_blocked'), { link: { href: safe, label: ctx.t('new_tab_open') } })
}

/**
 * The path a `visit` answer navigates to, below the Martis base path, with
 * its params added to the query string. The query the path already has is
 * kept as written, the params follow it, and a `#fragment` stays at the end.
 * Null, undefined, `false` and `''` params are left out, as Nova's
 * `Nova.url()` drops falsy ones. Returns null when the result is not a safe
 * same-origin path.
 */
export function actionVisitTarget(path: unknown, params: unknown): string | null {
  if (typeof path !== 'string' || path === '') return null

  const query = new URLSearchParams()
  if (params !== null && typeof params === 'object') {
    for (const [key, value] of Object.entries(params as Record<string, unknown>)) {
      if (value === null || value === undefined || value === false || value === '') continue
      query.append(key, String(value))
    }
  }

  const target = `/${path.replace(/^\/+/, '')}`
  const hashAt = target.indexOf('#')
  const fragment = hashAt === -1 ? '' : target.slice(hashAt)
  const beforeFragment = hashAt === -1 ? target : target.slice(0, hashAt)
  const queryAt = beforeFragment.indexOf('?')
  const pathname = queryAt === -1 ? beforeFragment : beforeFragment.slice(0, queryAt)
  const search = [queryAt === -1 ? '' : beforeFragment.slice(queryAt + 1), query.toString()].filter((part) => part !== '').join('&')

  return safeInternalPath(`${pathname}${search !== '' ? `?${search}` : ''}${fragment}`)
}

/**
 * The `$data` of a `modal` or `emit` answer as an object. PHP serialises an
 * empty array as `[]`, so anything that is not a plain object reaches the
 * component or the listener as `{}`.
 */
function answerData(value: unknown): Record<string, unknown> {
  if (value === null || typeof value !== 'object' || Array.isArray(value)) return {}
  const proto = Object.getPrototypeOf(value) as unknown
  return proto === Object.prototype || proto === null ? (value as Record<string, unknown>) : {}
}

/**
 * Download `url` as `filename` through a temporary link, as Nova does. An
 * `http(s)` URL, a same-origin path and a `blob:` URL the page built itself
 * download; any other URL (`javascript:`, `data:`, ...) is refused with a
 * console error, since clicking it would run in the panel origin.
 */
export function triggerDownload(url: string, filename: string | null): void {
  const safe = safeNavigationUrl(url, { blob: true })
  if (safe === null) {
    console.error('[martis] action response: refused to download a URL that is not http(s) or a path', url)
    return
  }

  const link = document.createElement('a')
  link.href = safe
  link.setAttribute('download', filename ?? '')
  link.style.display = 'none'
  document.body.appendChild(link)
  link.click()
  document.body.removeChild(link)
}

/**
 * Carry out the answer of an action run (`ActionResponse`), as Nova 5's
 * `handleActionResponse` does. "Done" hides the action's modal and refreshes
 * the page it ran from. See the response table of `docs/actions.md`.
 */
export function handleActionResponse(response: ActionResponsePayload | undefined, ctx: ActionResponseContext): void {
  const data = response?.data ?? {}
  const text = (value: unknown): string | null => (typeof value === 'string' && value !== '' ? value : null)
  const success = (message?: unknown) => ctx.addToast('success', text(message) ?? ctx.t('action_success'))
  const done = () => {
    ctx.hide()
    ctx.refresh()
  }

  // Only an `openInNewTab` answer uses the tab opened on the click.
  if (response?.type !== 'openInNewTab') closeActionTab(ctx.tab)

  switch (response?.type) {
    case 'message':
      success(data.message)
      done()
      return
    case 'danger':
      ctx.addToast('error', text(data.message) ?? ctx.t('action_failed'))
      done()
      return
    case 'redirect': {
      const url = text(data.url)
      if (url !== null) {
        const safe = safeNavigationUrl(url)
        if (safe === null) {
          console.error('[martis] action response: refused to redirect to a URL that is not http(s) or a path', url)
          break
        }
        window.location.href = safe
        return
      }
      break
    }
    case 'visit': {
      const target = actionVisitTarget(data.path, data.params)
      if (target === null) {
        console.error('[martis] action response: refused to visit a path outside the app', data.path)
        break
      }
      success()
      ctx.hide()
      ctx.navigate(target)
      return
    }
    case 'openInNewTab': {
      const url = text(data.url)
      if (url !== null) openAnswerInNewTab(url, ctx)
      else closeActionTab(ctx.tab)
      done()
      return
    }
    case 'download': {
      const url = text(data.url)
      if (url !== null) triggerDownload(url, text(data.filename))
      success()
      done()
      return
    }
    case 'emit': {
      const event = text(data.event)
      if (event !== null) {
        martisEventBus.emit(event, answerData(data.data))
      }
      success()
      done()
      return
    }
    case 'modal': {
      const component = text(data.component) ?? ''
      success()
      ctx.hide()
      if (ctx.showModal !== null && component !== '' && ctx.showModal(component, answerData(data.data), ctx.refresh)) {
        return
      }
      console.warn(`[martis] action response: no component is registered for "${component}"`)
      ctx.refresh()
      return
    }
    case 'openCreate': {
      const resource = text(data.resource)
      if (resource !== null && ctx.onOpenCreate) {
        ctx.hide()
        ctx.onOpenCreate(resource)
        return
      }
      break
    }
    case 'openDetail':
    case 'openUpdate': {
      const resource = text(data.resource)
      const open = response?.type === 'openDetail' ? ctx.onOpenDetail : ctx.onOpenUpdate
      if (resource !== null && data.recordId != null && open) {
        ctx.hide()
        open(resource, data.recordId as string | number)
        return
      }
      break
    }
  }

  success()
  done()
}
