import { describe, it, expect } from 'vitest'
import { hasInPlaceDetail, inPlaceMarker, inPlaceState, isInPlaceTransition, opensDetailInPlace } from '@/lib/inPlaceDetail'
import { config } from '@/lib/config'
import { componentRegistry } from '@/lib/componentRegistry'
import type { ResourceSchema } from '@/types'

componentRegistry.register('martis:drawer-detail', (() => null) as never)

const index = { pathname: '/resources/conversations', state: null }
const opened = { pathname: '/resources/conversations/7', state: inPlaceState({ backdrop: '/resources/conversations', depth: 1 }) }

describe('inPlaceMarker', () => {
  it('reads the marker a history entry carries, and nothing else', () => {
    expect(inPlaceMarker(opened.state)).toEqual({ backdrop: '/resources/conversations', depth: 1 })
    expect(inPlaceMarker(null)).toBeNull()
    expect(inPlaceMarker({ other: 1 })).toBeNull()
    expect(inPlaceMarker({ martisInPlaceDetail: { depth: 2 } })).toBeNull()
    expect(inPlaceMarker({ martisInPlaceDetail: { backdrop: '/x', depth: -3 } })).toEqual({ backdrop: '/x', depth: 1 })
  })
})

describe('isInPlaceTransition', () => {
  it('treats opening, swapping and closing a record over its index as the same page', () => {
    const swapped = { pathname: '/resources/conversations/8', state: inPlaceState({ backdrop: '/resources/conversations', depth: 2 }) }

    expect(isInPlaceTransition(index, opened)).toBe(true)
    expect(isInPlaceTransition(opened, swapped)).toBe(true)
    expect(isInPlaceTransition(opened, index)).toBe(true)
  })

  it('treats closing a deep-linked record as the same page', () => {
    const deepLink = { pathname: '/resources/conversations/7', state: null }
    const closed = {
      pathname: '/resources/conversations',
      state: inPlaceState({ backdrop: '/resources/conversations', depth: 1, from: '/resources/conversations/7' }),
    }

    expect(isInPlaceTransition(deepLink, closed)).toBe(true)
  })

  it('keeps every other move a page navigation', () => {
    expect(isInPlaceTransition(index, { pathname: '/resources/conversations/7', state: null })).toBe(false)
    expect(isInPlaceTransition(opened, { pathname: '/resources/users', state: null })).toBe(false)
    expect(isInPlaceTransition(index, { pathname: '/dashboards/main', state: null })).toBe(false)
  })
})

describe('opensDetailInPlace', () => {
  const withDetail = { overrides: { detail: { component: 'martis:drawer-detail', params: {} } } } as unknown as ResourceSchema

  it('opens a record in place when the resource declares a detail override', () => {
    expect(opensDetailInPlace(withDetail, 'conversations')).toBe(true)
    expect(opensDetailInPlace({ overrides: {} } as unknown as ResourceSchema, 'conversations')).toBe(false)
    expect(opensDetailInPlace(undefined, 'conversations')).toBe(false)
  })

  it('falls back to the full detail page for an override the registry cannot resolve', () => {
    const unknown = { overrides: { detail: { component: 'app:not-registered', params: {} } } } as unknown as ResourceSchema

    expect(hasInPlaceDetail(unknown)).toBe(false)
    expect(opensDetailInPlace(unknown, 'conversations')).toBe(false)
  })

  it('leaves a record with a custom record URL to that URL, while its own URL still opens in place', () => {
    const previous = config.resourceRecordUrls
    config.resourceRecordUrls = { conversations: '/tools/inbox/{id}' }
    try {
      expect(opensDetailInPlace(withDetail, 'conversations')).toBe(false)
      expect(hasInPlaceDetail(withDetail)).toBe(true)
    } finally {
      config.resourceRecordUrls = previous
    }
  })
})
