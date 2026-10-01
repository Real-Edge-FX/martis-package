import { martisEventBus } from '@/lib/eventBus'
import { openExternal } from '@/lib/openExternal'
import { safeInternalPath } from '@/lib/safeInternalPath'

/** The answer of an action run: `ActionResponse::jsonSerialize()`, `{ type, data }`. */
export interface ActionResponsePayload {
  type?: string
  data?: Record<string, unknown>
}

/** What the component that ran an action lends the dispatcher. */
export interface ActionResponseContext {
  t: (key: string) => string
  addToast: (type: 'success' | 'error', message: string) => void
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
}

/**
 * The path a `visit` answer navigates to, below the Martis base path, with
 * its params as a query string. Null, undefined, `false` and `''` params
 * are left out, as Nova's `Nova.url()` drops falsy ones. Returns null when
 * the result is not a safe same-origin path.
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
  const search = query.toString()

  return safeInternalPath(`/${path.replace(/^\/+/, '')}${search !== '' ? `?${search}` : ''}`)
}

/** Download `url` as `filename` through a temporary link, as Nova does. */
export function triggerDownload(url: string, filename: string | null): void {
  const link = document.createElement('a')
  link.href = url
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
        window.location.href = url
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
      if (url !== null) openExternal(url)
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
        martisEventBus.emit(event, (data.data ?? {}) as Record<string, unknown>)
      }
      success()
      done()
      return
    }
    case 'modal': {
      const component = text(data.component) ?? ''
      success()
      ctx.hide()
      if (ctx.showModal !== null && component !== '' && ctx.showModal(component, (data.data ?? {}) as Record<string, unknown>, ctx.refresh)) {
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
