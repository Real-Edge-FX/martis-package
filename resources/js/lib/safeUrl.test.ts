import { describe, expect, it } from 'vitest'
import { isSafeNavigationUrl, safeHref, safeNavigationUrl, urlProtocol } from './safeUrl'

/*
 * The guard every URL the SPA hands to the browser as a navigation target
 * goes through (a server-given redirect, a download, a notification action,
 * a CTA link): only the schemes a person expects to leave the page through
 * pass, as the browser's own URL parser reads them.
 */

describe('safeNavigationUrl', () => {
  it.each([
    'https://example.com/report',
    'http://example.com',
    'HTTPS://EXAMPLE.COM',
    '//cdn.example.com/file.csv',
    '/martis/resources/posts',
    'reports/export.csv',
    '?page=2',
    '#section',
  ])('lets %s through unchanged', (url) => {
    expect(safeNavigationUrl(url)).toBe(url)
  })

  it.each([
    'javascript:alert(1)',
    'JaVaScRiPt:alert(1)',
    ' javascript:alert(1)',
    '\tjavascript:alert(1)',
    'java\tscript:alert(1)',
    'java\nscript:alert(1)',
    '\u0001javascript:alert(1)',
    'data:text/html,<script>alert(1)</script>',
    'vbscript:msgbox(1)',
    'file:///etc/passwd',
    'blob:https://example.com/0f0f0f0f',
    'mailto:someone@example.com',
    'tel:+351000000000',
    'ftp://example.com/file',
  ])('refuses %j', (url) => {
    expect(safeNavigationUrl(url)).toBeNull()
  })

  it('refuses what is not a non-empty string', () => {
    expect(safeNavigationUrl(null)).toBeNull()
    expect(safeNavigationUrl(undefined)).toBeNull()
    expect(safeNavigationUrl(42)).toBeNull()
    expect(safeNavigationUrl({})).toBeNull()
    expect(safeNavigationUrl('')).toBeNull()
    expect(safeNavigationUrl('   ')).toBeNull()
  })

  it('opts into blob: for a download the page built itself', () => {
    expect(safeNavigationUrl('blob:https://example.com/0f0f0f0f', { blob: true })).toBe('blob:https://example.com/0f0f0f0f')
    expect(safeNavigationUrl('javascript:alert(1)', { blob: true })).toBeNull()
  })

  it('opts into mailto: and tel: for a contact link', () => {
    expect(safeNavigationUrl('mailto:someone@example.com', { contact: true })).toBe('mailto:someone@example.com')
    expect(safeNavigationUrl('tel:+351000000000', { contact: true })).toBe('tel:+351000000000')
    expect(safeNavigationUrl('javascript:alert(1)', { contact: true })).toBeNull()
  })

  it('has a boolean form', () => {
    expect(isSafeNavigationUrl('https://example.com')).toBe(true)
    expect(isSafeNavigationUrl('javascript:alert(1)')).toBe(false)
  })
})

describe('safeHref', () => {
  it('returns a safe link target as is, a contact link included', () => {
    expect(safeHref('https://example.com/upgrade')).toBe('https://example.com/upgrade')
    expect(safeHref('/pricing')).toBe('/pricing')
    expect(safeHref('mailto:sales@example.com')).toBe('mailto:sales@example.com')
  })

  it('returns undefined for anything else, so the anchor goes nowhere', () => {
    expect(safeHref('javascript:alert(1)')).toBeUndefined()
    expect(safeHref('data:text/html,x')).toBeUndefined()
    expect(safeHref(null)).toBeUndefined()
    expect(safeHref(undefined)).toBeUndefined()
  })
})

describe('urlProtocol', () => {
  it('reads the protocol the way the browser parses the value', () => {
    expect(urlProtocol('java\tscript:alert(1)')).toBe('javascript:')
    expect(urlProtocol('/relative')).toBe(window.location.protocol)
  })
})
