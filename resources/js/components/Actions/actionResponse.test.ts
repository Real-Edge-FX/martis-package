import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { actionVisitTarget, handleActionResponse, type ActionResponseContext, type ActionResponsePayload } from './actionResponse'
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

  it('adds the params to the query the path already has', () => {
    // What PHP sends for visit('/resources/users?view=open', ['page' => 2]).
    const ctx = context()
    handleActionResponse(JSON.parse('{"type":"visit","data":{"path":"\\/resources\\/users?view=open","params":{"page":2}}}') as ActionResponsePayload, ctx)

    expect(ctx.navigate).toHaveBeenCalledWith('/resources/users?view=open&page=2')
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

  // The answers below are what PHP sends for `emit('reports:refresh')` and
  // `modal('generated-token')` with no data: `json_encode` writes the empty
  // array as `[]` (recorded with `json_encode(ActionResponse::modal(...))`).
  it('emits an empty object for the empty data PHP serialises as []', () => {
    const listener = vi.fn()
    martisEventBus.on('reports:refresh', listener)
    handleActionResponse(JSON.parse('{"type":"emit","data":{"event":"reports:refresh","data":[]}}') as ActionResponsePayload, context())
    martisEventBus.off('reports:refresh', listener)

    expect(listener).toHaveBeenCalledWith({})
    expect(Array.isArray(listener.mock.calls[0][0])).toBe(false)
  })

  it('emits a list PHP casts to an object as it came', () => {
    const listener = vi.fn()
    martisEventBus.on('reports:refresh', listener)
    handleActionResponse(JSON.parse('{"type":"emit","data":{"event":"reports:refresh","data":{"0":"a","1":"b"}}}') as ActionResponsePayload, context())
    martisEventBus.off('reports:refresh', listener)

    expect(listener).toHaveBeenCalledWith({ 0: 'a', 1: 'b' })
  })

  it('shows the component a modal answer names, and refreshes only when it closes', () => {
    const ctx = context()
    handleActionResponse({ type: 'modal', data: { component: 'generated-token', data: { token: 's3cret' } } }, ctx)

    expect(ctx.showModal).toHaveBeenCalledWith('generated-token', { token: 's3cret' }, ctx.refresh)
    expect(ctx.hide).toHaveBeenCalled()
    expect(ctx.refresh).not.toHaveBeenCalled()
  })

  it('hands a modal component an empty object for the empty data PHP serialises as []', () => {
    const ctx = context()
    handleActionResponse(JSON.parse('{"type":"modal","data":{"component":"generated-token","data":[]}}') as ActionResponsePayload, ctx)

    expect(ctx.showModal).toHaveBeenCalledWith('generated-token', {}, ctx.refresh)
  })

  it.each([
    ['a string', '"token"'],
    ['a number', '42'],
    ['null', 'null'],
    ['a list', '["a","b"]'],
  ])('hands a modal component an empty object for %s', (_name, json) => {
    const ctx = context()
    handleActionResponse(JSON.parse(`{"type":"modal","data":{"component":"generated-token","data":${json}}}`) as ActionResponsePayload, ctx)

    expect(ctx.showModal).toHaveBeenCalledWith('generated-token', {}, ctx.refresh)
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
    expect(actionVisitTarget('/\\evil.example?view=open#top', { page: 2 })).toBeNull()
    expect(actionVisitTarget(42, {})).toBeNull()
  })

  it('keeps an absolute URL inside the app, as a path', () => {
    expect(actionVisitTarget('https://evil.example/x', {})).toBe('/https://evil.example/x')
    expect(actionVisitTarget('https://evil.example/x', { page: 2 })).toBe('/https://evil.example/x?page=2')
  })

  it('appends the params to a plain path', () => {
    expect(actionVisitTarget('/resources/users', { view: 'open', page: 2 })).toBe('/resources/users?view=open&page=2')
    expect(actionVisitTarget('resources/users', {})).toBe('/resources/users')
  })

  it('keeps the query of the path, and adds the params after it', () => {
    expect(actionVisitTarget('/resources/users?view=open', { page: 2 })).toBe('/resources/users?view=open&page=2')
    expect(actionVisitTarget('/resources/users?view=open', {})).toBe('/resources/users?view=open')
    expect(actionVisitTarget('/resources/users?', { page: 2 })).toBe('/resources/users?page=2')
  })

  it('keeps the fragment of the path at the end', () => {
    expect(actionVisitTarget('/resources/users#top', { page: 2 })).toBe('/resources/users?page=2#top')
    expect(actionVisitTarget('/resources/users?view=open#top', { page: 2 })).toBe('/resources/users?view=open&page=2#top')
    expect(actionVisitTarget('/resources/users#top?not=query', { page: 2 })).toBe('/resources/users?page=2#top?not=query')
  })

  it('encodes the params, and leaves out the empty ones', () => {
    expect(actionVisitTarget('/resources/users', { q: 'a?b#c&d', off: false, empty: '', none: null, missing: undefined })).toBe('/resources/users?q=a%3Fb%23c%26d')
  })
})
