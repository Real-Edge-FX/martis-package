import { describe, it, expect } from 'vitest'
import { safeInternalPath, isSafeInternalPath } from './safeInternalPath'

describe('safeInternalPath', () => {
  it.each([
    '/',
    '/resources/posts/1',
    '/resources/posts/1/edit?tab=details#comments',
    '/resources/posts?filters=%2F%2Fnot-a-host',
    '/tools/reports?next=//still-a-query-value',
  ])('keeps the same-origin path %s', (path) => {
    expect(safeInternalPath(path)).toBe(path)
    expect(isSafeInternalPath(path)).toBe(true)
  })

  it.each([
    ['protocol-relative', '//evil.example/login'],
    ['backslash after the slash', '/\\evil.example'],
    ['backslash later in the path', '/resources\\..\\..\\evil'],
    ['tab between the slashes', '/\t/evil.example'],
    ['newline between the slashes', '/\n/evil.example'],
    ['absolute http URL', 'https://evil.example/'],
    ['javascript scheme', 'javascript:alert(1)'],
    ['relative path', 'resources/posts'],
    ['empty string', ''],
  ])('rejects a %s target', (_label, value) => {
    expect(safeInternalPath(value)).toBeNull()
    expect(isSafeInternalPath(value)).toBe(false)
  })

  it('rejects null and undefined', () => {
    expect(safeInternalPath(null)).toBeNull()
    expect(safeInternalPath(undefined)).toBeNull()
  })
})
