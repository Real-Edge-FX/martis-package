import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { actionVisitTarget, handleActionResponse, type ActionResponseContext } from './actionResponse'
import { martisEventBus } from '@/lib/eventBus'

// The answer of an action run (ActionResponse), handled as Nova 5's
// handleActionResponse handles it (spec section 4.3).

function context(overrides: Partial<ActionResponseContext> = {}) {
  return {
    t: (key: string) => key,
    addToast: vi.fn(),
    navigate: vi.fn(),
    hide: vi.fn(),
    refresh: vi.fn(),
    showModal: vi.fn(() => true),
    ...overrides,
  }
}

const original = window.location

beforeEach(() => {
  Object.defineProperty(window, 'location', { configurable: true, value: { href: 'http://localhost/martis/resources/posts' } })
})

afterEach(() => {
  Object.defineProperty(window, 'location', { configurable: true, value: original })
  vi.restoreAllMocks()
})

describe('handleActionResponse', () => {
  it('shows a message, then hides and refreshes', () => {
    const ctx = context()
    handleActionResponse({ type: 'message', data: { message: 'Archived.' } }, ctx)

    expect(ctx.addToast).toHaveBeenCalledWith('success', 'Archived.')
    expect(ctx.hide).toHaveBeenCalled()
    expect(ctx.refresh).toHaveBeenCalled()
  })

  it('shows a danger message as an error', () => {
    const ctx = context()
    handleActionResponse({ type: 'danger', data: { message: 'Nope.' } }, ctx)

    expect(ctx.addToast).toHaveBeenCalledWith('error', 'Nope.')
  })

  it('redirects with a full page load', () => {
    const ctx = context()
    handleActionResponse({ type: 'redirect', data: { url: 'https://example.com/report' } }, ctx)

    expect(window.location.href).toBe('https://example.com/report')
    expect(ctx.hide).not.toHaveBeenCalled()
  })

  it('visits a path below the Martis base path, inside the SPA, with its params', () => {
    const ctx = context()
    handleActionResponse({ type: 'visit', data: { path: '/resources/users', params: { view: 'open', page: 2, empty: '', off: false, none: null } } }, ctx)

    expect(ctx.navigate).toHaveBeenCalledWith('/resources/users?view=open&page=2')
    expect(ctx.hide).toHaveBeenCalled()
    expect(ctx.refresh).not.toHaveBeenCalled()
    expect(ctx.addToast).toHaveBeenCalledWith('success', 'action_success')
  })

  it('refuses to visit a path that leaves the app', () => {
    const error = vi.spyOn(console, 'error').mockImplementation(() => {})
    const ctx = context()
    handleActionResponse({ type: 'visit', data: { path: '/\\evil.example' } }, ctx)

    expect(ctx.navigate).not.toHaveBeenCalled()
    expect(error).toHaveBeenCalled()
    expect(ctx.refresh).toHaveBeenCalled()
  })

  it('opens a new tab without a toast', () => {
    const open = vi.spyOn(window, 'open').mockImplementation(() => null)
    const ctx = context()
    handleActionResponse({ type: 'openInNewTab', data: { url: 'https://example.com/doc' } }, ctx)

    expect(open).toHaveBeenCalledWith('https://example.com/doc', '_blank', 'noopener,noreferrer')
    expect(ctx.addToast).not.toHaveBeenCalled()
    expect(ctx.refresh).toHaveBeenCalled()
  })

  it('downloads through a link named after the file', () => {
    const clicked: HTMLAnchorElement[] = []
    vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(function (this: HTMLAnchorElement) {
      clicked.push(this)
    })
    const ctx = context()
    handleActionResponse({ type: 'download', data: { filename: 'posts.csv', url: 'http://localhost/exports/posts.csv' } }, ctx)

    expect(clicked).toHaveLength(1)
    expect(clicked[0].getAttribute('download')).toBe('posts.csv')
    expect(clicked[0].href).toBe('http://localhost/exports/posts.csv')
    expect(document.body.contains(clicked[0])).toBe(false)
    expect(ctx.refresh).toHaveBeenCalled()
  })

  it('emits a client-side event on the Martis event bus', () => {
    const listener = vi.fn()
    martisEventBus.on('reports:refresh', listener)
    const ctx = context()
    handleActionResponse({ type: 'emit', data: { event: 'reports:refresh', data: { id: 7 } } }, ctx)
    martisEventBus.off('reports:refresh', listener)

    expect(listener).toHaveBeenCalledWith({ id: 7 })
    expect(ctx.refresh).toHaveBeenCalled()
  })

  it('shows the component a modal answer names, and refreshes only when it closes', () => {
    const ctx = context()
    handleActionResponse({ type: 'modal', data: { component: 'generated-token', data: { token: 's3cret' } } }, ctx)

    expect(ctx.showModal).toHaveBeenCalledWith('generated-token', { token: 's3cret' }, ctx.refresh)
    expect(ctx.hide).toHaveBeenCalled()
    expect(ctx.refresh).not.toHaveBeenCalled()
  })

  it('warns and refreshes when no component is registered for a modal answer', () => {
    const warn = vi.spyOn(console, 'warn').mockImplementation(() => {})
    const ctx = context({ showModal: vi.fn(() => false) })
    handleActionResponse({ type: 'modal', data: { component: 'missing', data: {} } }, ctx)

    expect(warn).toHaveBeenCalledWith('[martis] action response: no component is registered for "missing"')
    expect(ctx.refresh).toHaveBeenCalled()
  })

  it('warns and refreshes outside the shell, where no modal host exists', () => {
    const warn = vi.spyOn(console, 'warn').mockImplementation(() => {})
    const ctx = context({ showModal: null })
    handleActionResponse({ type: 'modal', data: { component: 'generated-token', data: {} } }, ctx)

    expect(warn).toHaveBeenCalled()
    expect(ctx.refresh).toHaveBeenCalled()
  })

  it('opens a drawer when the caller can, else falls back to a toast', () => {
    const onOpenDetail = vi.fn()
    const withDrawer = context({ onOpenDetail })
    handleActionResponse({ type: 'openDetail', data: { resource: 'posts', recordId: 3 } }, withDrawer)
    expect(onOpenDetail).toHaveBeenCalledWith('posts', 3)
    expect(withDrawer.refresh).not.toHaveBeenCalled()

    const without = context()
    handleActionResponse({ type: 'openCreate', data: { resource: 'posts' } }, without)
    expect(without.addToast).toHaveBeenCalledWith('success', 'action_success')
    expect(without.refresh).toHaveBeenCalled()
  })

  it('treats an unknown or missing answer as a success', () => {
    const ctx = context()
    handleActionResponse(undefined, ctx)

    expect(ctx.addToast).toHaveBeenCalledWith('success', 'action_success')
    expect(ctx.refresh).toHaveBeenCalled()
  })
})

describe('actionVisitTarget', () => {
  it('keeps a protocol-relative path inside the app, as Nova\'s ltrim does', () => {
    expect(actionVisitTarget('//evil.example', {})).toBe('/evil.example')
  })

  it('refuses a path the URL parser reads as another origin', () => {
    expect(actionVisitTarget('/\\evil.example', {})).toBeNull()
    expect(actionVisitTarget(42, {})).toBeNull()
  })
})
