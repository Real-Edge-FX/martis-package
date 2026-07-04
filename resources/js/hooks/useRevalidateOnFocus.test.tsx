import { describe, it, expect, vi, beforeEach } from 'vitest'
import { renderHook } from '@testing-library/react'
import { useRevalidateOnFocus } from './useRevalidateOnFocus'

/*
 * `useRevalidateOnFocus` — for manual-fetch Tools (no react-query) that
 * want to opt into the same "revalidate when the operator returns to this
 * tab" behaviour react-query's `refetchOnWindowFocus` gives Resources for
 * free. Fires on `visibilitychange` (becoming visible) and window `focus`;
 * cleans up both listeners on unmount.
 */

describe('useRevalidateOnFocus', () => {
  let originalVisibilityState: PropertyDescriptor | undefined

  beforeEach(() => {
    originalVisibilityState = Object.getOwnPropertyDescriptor(document, 'visibilityState')
  })

  function setVisibility(state: 'visible' | 'hidden') {
    Object.defineProperty(document, 'visibilityState', {
      configurable: true,
      get: () => state,
    })
  }

  function restoreVisibility() {
    if (originalVisibilityState) {
      Object.defineProperty(document, 'visibilityState', originalVisibilityState)
    }
  }

  it('calls onRevalidate when the tab becomes visible', () => {
    const cb = vi.fn()
    renderHook(() => useRevalidateOnFocus(cb))

    setVisibility('visible')
    document.dispatchEvent(new Event('visibilitychange'))

    expect(cb).toHaveBeenCalledTimes(1)
    restoreVisibility()
  })

  it('does NOT call onRevalidate when visibilitychange fires while hidden', () => {
    const cb = vi.fn()
    renderHook(() => useRevalidateOnFocus(cb))

    setVisibility('hidden')
    document.dispatchEvent(new Event('visibilitychange'))

    expect(cb).not.toHaveBeenCalled()
    restoreVisibility()
  })

  it('calls onRevalidate when the window regains focus', () => {
    const cb = vi.fn()
    renderHook(() => useRevalidateOnFocus(cb))

    window.dispatchEvent(new Event('focus'))

    expect(cb).toHaveBeenCalledTimes(1)
  })

  it('removes both listeners on unmount', () => {
    const cb = vi.fn()
    const { unmount } = renderHook(() => useRevalidateOnFocus(cb))

    unmount()

    setVisibility('visible')
    document.dispatchEvent(new Event('visibilitychange'))
    window.dispatchEvent(new Event('focus'))

    expect(cb).not.toHaveBeenCalled()
    restoreVisibility()
  })
})
