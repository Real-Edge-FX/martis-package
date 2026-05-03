import { describe, expect, it } from 'vitest'
import { isGroupActive, isLeafActive } from '@/lib/navigation'
import type { NavigationGroupChild, NavigationItem } from '@/types'

const at = (pathname: string, search = '') => ({ pathname, search })

const resource = (url: string, uriKey = 'r'): NavigationItem => ({
  type: 'resource',
  uriKey,
  label: 'R',
  url,
  count: null,
} as NavigationItem)

const link = (url: string, label = 'Link'): NavigationItem => ({
  type: 'link',
  label,
  url,
  external: false,
} as NavigationItem)

const filter = (url: string, label = 'F'): NavigationItem => ({
  type: 'filter',
  label,
  url,
} as NavigationItem)

describe('isLeafActive', () => {
  it('matches a resource leaf when pathname equals the item url', () => {
    expect(isLeafActive(resource('/resources/notes'), at('/resources/notes'))).toBe(true)
    expect(isLeafActive(resource('/resources/notes'), at('/resources/notes/123'))).toBe(false)
  })

  it('does NOT match a resource leaf while a filter param hangs in the URL', () => {
    // Resource and filter sibling share the pathname; the filter's
    // ?filters= query disambiguates so the bare resource keeps quiet
    // until hydration strips the param.
    expect(isLeafActive(resource('/resources/invoices'), at('/resources/invoices', '?filters=overdue'))).toBe(false)
  })

  it('matches a filter leaf when both pathname AND filter payload agree', () => {
    expect(isLeafActive(filter('/resources/invoices?filters=overdue'), at('/resources/invoices', '?filters=overdue'))).toBe(true)
    expect(isLeafActive(filter('/resources/invoices?filters=overdue'), at('/resources/invoices', '?filters=other'))).toBe(false)
    expect(isLeafActive(filter('/resources/invoices?filters=overdue'), at('/resources/invoices'))).toBe(false)
  })

  it('matches a plain link leaf on exact pathname', () => {
    expect(isLeafActive(link('/tools/system-status'), at('/tools/system-status'))).toBe(true)
    expect(isLeafActive(link('/tools/system-status'), at('/tools/system-status/sub'))).toBe(false)
  })
})

describe('isGroupActive — deepest-match-wins rule', () => {
  it('returns false when the group has no path()', () => {
    expect(isGroupActive(null, [resource('/foo')], at('/foo'))).toBe(false)
    expect(isGroupActive(undefined, [resource('/foo')], at('/foo'))).toBe(false)
    expect(isGroupActive('', [resource('/foo')], at('/foo'))).toBe(false)
  })

  it('returns true when the group path matches AND no descendant leaf matches', () => {
    expect(isGroupActive('/library', [resource('/library/notes')], at('/library'))).toBe(true)
  })

  it('returns false when the group path matches BUT a descendant leaf at the same URL would also match', () => {
    // Regression: pre-fix, NavLink default isActive lit up Library +
    // Notes group + Notas leaf simultaneously when all three pointed
    // at `/resources/notes` and the user landed there.
    const items: NavigationGroupChild[] = [
      {
        type: 'group',
        label: 'Notes',
        items: [resource('/resources/notes')],
      } as NavigationGroupChild,
    ]
    expect(isGroupActive('/resources/notes', items, at('/resources/notes'))).toBe(false)
  })

  it('returns false when a direct leaf descendant matches the same URL', () => {
    expect(isGroupActive('/notes', [resource('/notes')], at('/notes'))).toBe(false)
  })

  it('returns false when the group path does not match the pathname', () => {
    expect(isGroupActive('/library', [resource('/library/notes')], at('/somewhere/else'))).toBe(false)
  })
})
