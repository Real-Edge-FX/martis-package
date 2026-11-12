import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@/lib/config', () => ({ config: { auth: {} }, BASE_PATH: '/martis', API_BASE_URL: 'http://localhost/martis' }))

import { panelUrlTarget } from '@/lib/panelUrl'

const original = window.location

beforeEach(() => {
  Object.defineProperty(window, 'location', {
    configurable: true,
    value: { href: 'http://localhost/martis/login', pathname: '/martis/login', origin: 'http://localhost' },
  })
})

afterEach(() => {
  Object.defineProperty(window, 'location', { configurable: true, value: original })
})

describe('panelUrlTarget', () => {
  it('returns the path and query of a path under the base path', () => {
    expect(panelUrlTarget('/martis/sso/issue/partner?state=abc')).toBe('/martis/sso/issue/partner?state=abc')
    expect(panelUrlTarget('/martis/resources/users')).toBe('/martis/resources/users')
  })

  it.each([
    ['nothing', undefined],
    ['null', null],
    ['a number', 42],
    ['an object', { url: '/martis/x' }],
    ['an empty string', ''],
    ['an absolute URL', 'https://evil.example/martis/x'],
    ['an absolute URL on this origin', 'http://localhost/martis/x'],
    ['a protocol-relative URL', '//evil.example/martis/x'],
    ['a path outside the base path', '/other/x'],
    ['the base path alone', '/martis'],
    ['a path that only starts like the base path', '/martis-evil/x'],
    ['a dot segment that leaves the base path', '/martis/../other'],
    ['an encoded dot segment that leaves the base path', '/martis/%2e%2e/other'],
    ['a backslash that the browser reads as a slash', '/martis/..\\other'],
    ['a javascript: URL', 'javascript:alert(1)'],
  ])('returns null for %s', (_label, value) => {
    expect(panelUrlTarget(value)).toBeNull()
  })
})
