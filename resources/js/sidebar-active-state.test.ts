import { describe, expect, it } from 'vitest'
import { collectFilterKeys, isGroupActive, isLeafActive } from '@/lib/navigation'
import type { NavigationGroup, NavigationGroupChild, NavigationItem } from '@/types'

const at = (pathname: string, search = '') => ({ pathname, search })
const none: ReadonlySet<string> = new Set()

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

const menu = (items: NavigationGroupChild[]): NavigationGroup => ({ label: 'G', items } as NavigationGroup)

describe('isLeafActive', () => {
  it('matches a resource leaf when pathname equals the item url', () => {
    expect(isLeafActive(resource('/resources/notes'), at('/resources/notes'), none)).toBe(true)
    expect(isLeafActive(resource('/resources/notes'), at('/resources/notes/123'), none)).toBe(false)
  })

  it('leaves a resource leaf inactive while a filter item of the menu carries the URL filter', () => {
    // Resource and filter sibling share the pathname; the filter's
    // ?filters= payload picks the filter item, alone.
    const keys = collectFilterKeys([menu([resource('/resources/invoices'), filter('/resources/invoices?filters=overdue')])])
    expect(isLeafActive(resource('/resources/invoices'), at('/resources/invoices', '?filters=overdue'), keys)).toBe(false)
    expect(isLeafActive(filter('/resources/invoices?filters=overdue'), at('/resources/invoices', '?filters=overdue'), keys)).toBe(true)
  })

  it('keeps a resource leaf active under a filter no item of the menu carries', () => {
    // A dashboard tile, an email or the Filters panel: the page keeps its sidebar entry.
    const payments = resource('/resources/payments')
    const failed = at('/resources/payments', '?filters=' + encodeURIComponent('{"status":"failed"}'))
    expect(isLeafActive(payments, failed, collectFilterKeys([menu([payments])]))).toBe(true)

    const keys = collectFilterKeys([menu([resource('/resources/invoices'), filter('/resources/invoices?filters=overdue')])])
    expect(isLeafActive(resource('/resources/invoices'), at('/resources/invoices', '?filters=paid'), keys)).toBe(true)
    expect(isLeafActive(filter('/resources/invoices?filters=overdue'), at('/resources/invoices', '?filters=paid'), keys)).toBe(false)
    expect(isLeafActive(resource('/resources/invoices'), at('/resources/invoices'), keys)).toBe(true)
  })

  it('compares filter payloads whatever the order of their keys', () => {
    const item = filter('/resources/invoices?filters=' + encodeURIComponent('{"status":"overdue","team":3}'))
    const keys = collectFilterKeys([menu([resource('/resources/invoices'), item])])
    const reordered = at('/resources/invoices', '?filters=' + encodeURIComponent('{"team":3,"status":"overdue"}'))
    expect(isLeafActive(item, reordered, keys)).toBe(true)
    expect(isLeafActive(resource('/resources/invoices'), reordered, keys)).toBe(false)
  })

  it('reads the filter items of every group, nested ones included', () => {
    const keys = collectFilterKeys([
      menu([resource('/resources/invoices')]),
      menu([{ type: 'group', label: 'Saved', items: [filter('/resources/invoices?filters=overdue')] } as NavigationGroupChild]),
    ])
    expect(isLeafActive(resource('/resources/invoices'), at('/resources/invoices', '?filters=overdue'), keys)).toBe(false)
  })

  it('matches a filter leaf when both pathname AND filter payload agree', () => {
    expect(isLeafActive(filter('/resources/invoices?filters=overdue'), at('/resources/invoices', '?filters=overdue'), none)).toBe(true)
    expect(isLeafActive(filter('/resources/invoices?filters=overdue'), at('/resources/invoices', '?filters=other'), none)).toBe(false)
    expect(isLeafActive(filter('/resources/invoices?filters=overdue'), at('/resources/invoices'), none)).toBe(false)
  })

  it('matches a plain link leaf on exact pathname', () => {
    expect(isLeafActive(link('/tools/system-status'), at('/tools/system-status'), none)).toBe(true)
    expect(isLeafActive(link('/tools/system-status'), at('/tools/system-status/sub'), none)).toBe(false)
  })
})

describe('isGroupActive — deepest-match-wins rule', () => {
  it('returns false when the group has no path()', () => {
    expect(isGroupActive(null, [resource('/foo')], at('/foo'), none)).toBe(false)
    expect(isGroupActive(undefined, [resource('/foo')], at('/foo'), none)).toBe(false)
    expect(isGroupActive('', [resource('/foo')], at('/foo'), none)).toBe(false)
  })

  it('returns true when the group path matches AND no descendant leaf matches', () => {
    expect(isGroupActive('/library', [resource('/library/notes')], at('/library'), none)).toBe(true)
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
    expect(isGroupActive('/resources/notes', items, at('/resources/notes'), none)).toBe(false)
  })

  it('returns false when a direct leaf descendant matches the same URL', () => {
    expect(isGroupActive('/notes', [resource('/notes')], at('/notes'), none)).toBe(false)
  })

  it('returns false when the group path does not match the pathname', () => {
    expect(isGroupActive('/library', [resource('/library/notes')], at('/somewhere/else'), none)).toBe(false)
  })

  it('leaves the group header to a resource leaf active under an ad-hoc filter', () => {
    const items = [resource('/resources/payments')]
    const keys = collectFilterKeys([menu(items)])
    expect(isGroupActive('/resources/payments', items, at('/resources/payments', '?filters=x'), keys)).toBe(false)
  })
})
