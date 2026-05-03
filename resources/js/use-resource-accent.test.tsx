import { describe, expect, it } from 'vitest'
import { renderHook } from '@testing-library/react'
import { useResourceAccent } from '@/lib/useResourceAccent'

/**
 * The hook used to mutate `<html>` directly, which made every
 * resource-specific accent leak into the sidebar / topbar / sibling
 * badges. v1.8.8 returns wrapper props instead. These specs lock in
 * the contract.
 */
describe('useResourceAccent', () => {
  it('returns an empty object for null / undefined / empty', () => {
    expect(renderHook(() => useResourceAccent(null)).result.current).toEqual({})
    expect(renderHook(() => useResourceAccent(undefined)).result.current).toEqual({})
    expect(renderHook(() => useResourceAccent('')).result.current).toEqual({})
  })

  it('emits data-resource-accent for a named accent', () => {
    const { result } = renderHook(() => useResourceAccent('violet'))
    expect(result.current).toEqual({ 'data-resource-accent': 'violet' })
  })

  it('accepts arbitrary custom names (host theme extensibility)', () => {
    const { result } = renderHook(() => useResourceAccent('citrus-pop'))
    expect(result.current).toEqual({ 'data-resource-accent': 'citrus-pop' })
  })

  it('emits an inline style when accent is a hex (3, 6, or 8 digits)', () => {
    expect(renderHook(() => useResourceAccent('#abc')).result.current.style).toEqual({
      '--martis-accent': '#abc',
    })
    expect(renderHook(() => useResourceAccent('#7C3AED')).result.current.style).toEqual({
      '--martis-accent': '#7C3AED',
    })
    expect(renderHook(() => useResourceAccent('#7C3AEDFF')).result.current.style).toEqual({
      '--martis-accent': '#7C3AEDFF',
    })
  })

  it('does NOT mutate <html> (regression: pre-v1.8.8 behaviour)', () => {
    const before = document.documentElement.getAttribute('data-accent')
    const beforeStyle = document.documentElement.style.getPropertyValue('--martis-accent')
    renderHook(() => useResourceAccent('violet'))
    expect(document.documentElement.getAttribute('data-accent')).toBe(before)
    expect(document.documentElement.style.getPropertyValue('--martis-accent')).toBe(beforeStyle)
  })

  it('returns a stable object reference when accent does not change', () => {
    const { result, rerender } = renderHook(({ accent }) => useResourceAccent(accent), {
      initialProps: { accent: 'violet' as string | null },
    })
    const first = result.current
    rerender({ accent: 'violet' })
    expect(result.current).toBe(first)
  })
})
