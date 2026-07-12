import { describe, it, expect } from 'vitest'
import { getItemCount, mergeBadgeCounts } from './navigation'
import type { NavigationGroup, NavigationItem } from '@/types'

// Tools now publish a numeric count badge like Resources (v1.29.0).

const toolItem = (over: Partial<NavigationItem> = {}): NavigationItem =>
  ({ type: 'tool', label: 'Standards', url: '/tools/standards', icon: null, uriKey: 'standards', ...over }) as NavigationItem

describe('getItemCount — tools', () => {
  it('returns the count for a tool item', () => {
    expect(getItemCount(toolItem({ count: 749 } as Partial<NavigationItem>))).toBe(749)
  })
  it('returns null for a tool without a count', () => {
    expect(getItemCount(toolItem())).toBeNull()
  })
  it('returns null for a plain link', () => {
    expect(getItemCount({ type: 'link', label: 'Docs', url: '/x', icon: null } as NavigationItem)).toBeNull()
  })
})

describe('mergeBadgeCounts — tools', () => {
  it('applies a polled badge to a tool by uriKey', () => {
    const groups: NavigationGroup[] = [
      { label: 'Knowledge', items: [toolItem({ uriKey: 'standards' } as Partial<NavigationItem>)] },
    ]
    // Badges map is keyed by "{type}:{uriKey}".
    const merged = mergeBadgeCounts(groups, { 'tool:standards': 812 })
    const tool = merged[0].items[0] as NavigationItem
    expect(getItemCount(tool)).toBe(812)
  })

  it('does not apply a resource-keyed badge to a same-named tool (no collision)', () => {
    const groups: NavigationGroup[] = [
      { label: 'Knowledge', items: [toolItem({ uriKey: 'standards', count: 5 } as Partial<NavigationItem>)] },
    ]
    // A resource count for "standards" must NOT leak onto the tool "standards".
    const merged = mergeBadgeCounts(groups, { 'resource:standards': 100 })
    expect(getItemCount(merged[0].items[0] as NavigationItem)).toBe(5)
  })
})
