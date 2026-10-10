import { describe, it, expect } from 'vitest'
import { actionVisitTarget } from './actionVisitTarget'

describe('actionVisitTarget', () => {
  it('returns the path unchanged without params', () => {
    expect(actionVisitTarget('/admin/tools/mail', undefined)).toBe('/admin/tools/mail')
    expect(actionVisitTarget('/admin/tools/mail', [])).toBe('/admin/tools/mail')
    expect(actionVisitTarget('/admin/tools/mail', {})).toBe('/admin/tools/mail')
  })

  it('adds params to a bare path as a query string', () => {
    expect(actionVisitTarget('/admin/tools/mail', { contact_id: 42, tab: 'a' })).toBe('/admin/tools/mail?contact_id=42&tab=a')
  })

  it('appends params after the query the path already has', () => {
    expect(actionVisitTarget('/admin/tools/mail?draft=1', { contact_id: 42 })).toBe('/admin/tools/mail?draft=1&contact_id=42')
  })

  it('keeps a fragment at the end', () => {
    expect(actionVisitTarget('/admin/page#top', { a: 1 })).toBe('/admin/page?a=1#top')
    expect(actionVisitTarget('/admin/page?x=1#top', { a: 1 })).toBe('/admin/page?x=1&a=1#top')
    expect(actionVisitTarget('/admin/page#top', {})).toBe('/admin/page#top')
  })

  it('encodes spaces, ampersands and equals signs', () => {
    expect(actionVisitTarget('/p', { q: 'a b&c=d' })).toBe('/p?q=a+b%26c%3Dd')
  })

  it('leaves out null, undefined, false and empty values', () => {
    expect(actionVisitTarget('/p', { a: null, b: undefined, c: false, d: '', e: 0 })).toBe('/p?e=0')
  })

  it('serialises lists and nested maps the way PHP parses them', () => {
    const target = actionVisitTarget('/p', { ids: [1, 2], filter: { status: 'open' } })
    expect(decodeURIComponent(target as string)).toBe('/p?ids[]=1&ids[]=2&filter[status]=open')
  })

  it('returns null for a missing path', () => {
    expect(actionVisitTarget(undefined, { a: 1 })).toBeNull()
    expect(actionVisitTarget('', { a: 1 })).toBeNull()
  })
})
