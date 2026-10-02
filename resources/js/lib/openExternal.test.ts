import { afterEach, beforeEach, describe, expect, it, vi, type MockInstance } from 'vitest'
import { openExternal } from './openExternal'

/*
 * Hardening (F121): `openExternal()` handed any URL to `window.open`. Its
 * callers take the URL from an action answer and from the navigation config
 * (the command palette), which an application may build from stored data, so
 * it opens only http(s) URLs and same-origin paths and refuses the rest with
 * a console error.
 */

let openSpy: MockInstance<typeof window.open>
let errorSpy: MockInstance<typeof console.error>

beforeEach(() => {
  openSpy = vi.spyOn(window, 'open').mockImplementation(() => null)
  errorSpy = vi.spyOn(console, 'error').mockImplementation(() => {})
})

afterEach(() => {
  vi.restoreAllMocks()
})

describe('openExternal', () => {
  it.each(['https://example.com/docs', 'http://example.com', '/martis/resources/users', '//cdn.example.com/x'])('opens %s in a new tab without an opener', (url) => {
    openExternal(url)

    expect(openSpy).toHaveBeenCalledWith(url, '_blank', 'noopener,noreferrer')
    expect(errorSpy).not.toHaveBeenCalled()
  })

  it.each([
    'javascript:alert(1)',
    ' javascript:alert(1)',
    'java\tscript:alert(1)',
    'data:text/html,<script>alert(1)</script>',
    'vbscript:msgbox(1)',
    'file:///etc/passwd',
    'blob:https://example.com/0f0f0f0f',
    'mailto:someone@example.com',
    '',
  ])('refuses %j with a console error', (url) => {
    openExternal(url)

    expect(openSpy).not.toHaveBeenCalled()
    expect(errorSpy).toHaveBeenCalledTimes(1)
  })
})
