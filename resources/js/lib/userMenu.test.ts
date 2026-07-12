import { describe, it, expect } from 'vitest'
import { buildCustomMenuItems, type UserMenuCustomItem } from './userMenu'

// Simulate i18n: 'menu.api_keys' is a known key, everything else is literal.
const resolveLabel = (label: string) => (label === 'menu.api_keys' ? 'API Keys (localised)' : label)

const ITEMS: UserMenuCustomItem[] = [
  { label: 'menu.api_keys', url: '/api-keys' }, // key → resolved; default placement "before"
  { label: 'Raw Label', url: '/raw' }, // non-key → verbatim
  { separator: true },
  { label: 'menu.api_keys', url: '/after', position: 'after' }, // after Profile
]

describe('buildCustomMenuItems', () => {
  it('resolves labels through i18n and passes non-keys through verbatim', () => {
    const before = buildCustomMenuItems(ITEMS, 'before', resolveLabel)
    expect(before).toEqual([
      { label: 'API Keys (localised)', icon: undefined, url: '/api-keys' },
      { label: 'Raw Label', icon: undefined, url: '/raw' },
      { separator: true },
    ])
  })

  it('places items with position "after" only in the after slot', () => {
    expect(buildCustomMenuItems(ITEMS, 'after', resolveLabel)).toEqual([
      { label: 'API Keys (localised)', icon: undefined, url: '/after' },
    ])
  })

  it('defaults items without a position to "before" (backward compatible)', () => {
    const noPos: UserMenuCustomItem[] = [{ label: 'x', url: '/x' }]
    expect(buildCustomMenuItems(noPos, 'before', (l) => l)).toHaveLength(1)
    expect(buildCustomMenuItems(noPos, 'after', (l) => l)).toHaveLength(0)
  })

  it('returns an empty list for undefined items', () => {
    expect(buildCustomMenuItems(undefined, 'before', (l) => l)).toEqual([])
  })
})
