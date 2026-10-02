import { afterEach, beforeEach, describe, expect, it, vi, type MockInstance } from 'vitest'
import { handleActionResponse, triggerDownload, type ActionResponseContext } from './actionResponse'

/*
 * Hardening (F104 / F121): a `redirect`, `download` or `openInNewTab` answer
 * took the URL of the action's PHP code and assigned it to `location.href`,
 * clicked it as a link or handed it to `window.open`, with no check of the
 * scheme (the sibling `visit` answer is filtered). A `javascript:` URL
 * assigned there runs in the panel origin, so the answers now follow only
 * http(s) URLs and same-origin paths (plus `blob:` for a download) and refuse
 * everything else with a console error, as `visit` does.
 */

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
const START = 'http://localhost/martis/resources/posts'

let openSpy: MockInstance<typeof window.open>
let errorSpy: MockInstance<typeof console.error>
let clickSpy: MockInstance<HTMLAnchorElement['click']>

beforeEach(() => {
  Object.defineProperty(window, 'location', { configurable: true, value: { href: START } })
  openSpy = vi.spyOn(window, 'open').mockImplementation(() => null)
  errorSpy = vi.spyOn(console, 'error').mockImplementation(() => {})
  clickSpy = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {})
})

afterEach(() => {
  Object.defineProperty(window, 'location', { configurable: true, value: original })
  vi.restoreAllMocks()
})

const UNSAFE = [
  'javascript:alert(1)',
  'JaVaScRiPt:alert(1)',
  ' javascript:alert(1)',
  'java\tscript:alert(1)',
  'data:text/html,<script>alert(1)</script>',
  'vbscript:msgbox(1)',
  'file:///etc/passwd',
]

describe('a redirect answer', () => {
  it.each(UNSAFE)('refuses %j and reports it', (url) => {
    const ctx = context()
    handleActionResponse({ type: 'redirect', data: { url } }, ctx)

    expect(window.location.href).toBe(START)
    expect(errorSpy).toHaveBeenCalled()
    expect(errorSpy.mock.calls[0][0]).toContain('refused')
  })

  it.each(['https://example.com/report', 'http://example.com', '/martis/dashboards/main', '//cdn.example.com/x'])('follows %s', (url) => {
    handleActionResponse({ type: 'redirect', data: { url } }, context())

    expect(window.location.href).toBe(url)
    expect(errorSpy).not.toHaveBeenCalled()
  })

  it('still shows the done toast and hides the modal once the redirect was refused', () => {
    const ctx = context()
    handleActionResponse({ type: 'redirect', data: { url: 'javascript:alert(1)' } }, ctx)

    expect(ctx.hide).toHaveBeenCalled()
    expect(ctx.refresh).toHaveBeenCalled()
  })
})

describe('an openInNewTab answer', () => {
  it.each(UNSAFE)('does not open %j', (url) => {
    handleActionResponse({ type: 'openInNewTab', data: { url } }, context())

    expect(openSpy).not.toHaveBeenCalled()
    expect(errorSpy).toHaveBeenCalled()
  })

  it('opens an http(s) URL without an opener', () => {
    handleActionResponse({ type: 'openInNewTab', data: { url: 'https://example.com/report' } }, context())

    expect(openSpy).toHaveBeenCalledWith('https://example.com/report', '_blank', 'noopener,noreferrer')
  })
})

describe('a download answer', () => {
  it.each(UNSAFE)('does not click a link to %j', (url) => {
    const ctx = context()
    handleActionResponse({ type: 'download', data: { url, filename: 'report.csv' } }, ctx)

    expect(clickSpy).not.toHaveBeenCalled()
    expect(errorSpy).toHaveBeenCalled()
    expect(ctx.hide).toHaveBeenCalled()
  })

  it.each(['https://example.com/report.csv', '/storage/report.csv', 'blob:http://localhost/0f0f0f0f'])('downloads %s', (url) => {
    handleActionResponse({ type: 'download', data: { url, filename: 'report.csv' } }, context())

    expect(clickSpy).toHaveBeenCalledTimes(1)
    expect(errorSpy).not.toHaveBeenCalled()
  })

  it('refuses an unsafe URL in triggerDownload itself, leaving no link behind', () => {
    triggerDownload('javascript:alert(1)', 'x.csv')

    expect(clickSpy).not.toHaveBeenCalled()
    expect(document.querySelector('a[download]')).toBeNull()
  })
})
