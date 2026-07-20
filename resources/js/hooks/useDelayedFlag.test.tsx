import { describe, it, expect, beforeEach, afterEach, vi } from "vitest"
import { act, renderHook } from "@testing-library/react"
import { useDelayedFlag } from "./useDelayedFlag"

const OPTS = { minVisibleMs: 400, maxVisibleMs: 10000 }

beforeEach(() => {
  vi.useFakeTimers()
})

afterEach(() => {
  vi.runOnlyPendingTimers()
  vi.useRealTimers()
})

describe("useDelayedFlag", () => {
  it("stays false while the signal is idle", () => {
    const { result } = renderHook(({ a }) => useDelayedFlag(a, OPTS), {
      initialProps: { a: false },
    })
    expect(result.current).toBe(false)
  })

  it("turns true immediately when the signal goes active", () => {
    const { result, rerender } = renderHook(({ a }) => useDelayedFlag(a, OPTS), {
      initialProps: { a: false },
    })

    act(() => {
      rerender({ a: true })
    })

    expect(result.current).toBe(true)
  })

  it("holds visible at least minVisibleMs after the signal clears early", () => {
    const { result, rerender } = renderHook(({ a }) => useDelayedFlag(a, OPTS), {
      initialProps: { a: false },
    })

    act(() => {
      rerender({ a: true })
    })
    expect(result.current).toBe(true)

    // Signal clears after only 50ms.
    act(() => {
      vi.advanceTimersByTime(50)
      rerender({ a: false })
    })
    expect(result.current).toBe(true) // still inside the 400ms floor

    act(() => {
      vi.advanceTimersByTime(300) // total 350ms
    })
    expect(result.current).toBe(true)

    act(() => {
      vi.advanceTimersByTime(100) // total 450ms > 400ms floor
    })
    expect(result.current).toBe(false)
  })

  it("hides promptly once the signal clears past the min window", () => {
    const { result, rerender } = renderHook(({ a }) => useDelayedFlag(a, OPTS), {
      initialProps: { a: false },
    })

    act(() => {
      rerender({ a: true })
    })
    act(() => {
      vi.advanceTimersByTime(2000) // slow switch, well past the floor
      rerender({ a: false })
    })

    // No artificial extra time — it clears immediately.
    expect(result.current).toBe(false)
  })

  it("force-hides after maxVisibleMs even if the signal stays active", () => {
    const { result, rerender } = renderHook(({ a }) => useDelayedFlag(a, OPTS), {
      initialProps: { a: false },
    })

    act(() => {
      rerender({ a: true })
    })
    expect(result.current).toBe(true)

    act(() => {
      vi.advanceTimersByTime(10000) // hit the safety cap
    })
    expect(result.current).toBe(false)

    // Stays down while the signal is still stuck active (cap latch).
    act(() => {
      vi.advanceTimersByTime(5000)
    })
    expect(result.current).toBe(false)
  })

  it("can show again after a capped switch finally ends and a new one begins", () => {
    const { result, rerender } = renderHook(({ a }) => useDelayedFlag(a, OPTS), {
      initialProps: { a: false },
    })

    act(() => {
      rerender({ a: true })
    })
    act(() => {
      vi.advanceTimersByTime(10000) // cap fires, latch set
    })
    expect(result.current).toBe(false)

    // The stuck switch finally ends...
    act(() => {
      rerender({ a: false })
    })
    // ...and a fresh switch shows the overlay again.
    act(() => {
      rerender({ a: true })
    })
    expect(result.current).toBe(true)
  })

  it("re-shows when resetKey changes after the cap, even if active never went false", () => {
    const { result, rerender } = renderHook(
      ({ a, k }) => useDelayedFlag(a, { ...OPTS, resetKey: k }),
      { initialProps: { a: false, k: 0 } },
    )

    act(() => {
      rerender({ a: true, k: 0 })
    })
    expect(result.current).toBe(true)

    act(() => {
      vi.advanceTimersByTime(10000) // cap fires, latch set
    })
    expect(result.current).toBe(false)

    // A fresh switch bumps the generation (resetKey) while the previous one is
    // still active — the latch releases and the overlay shows again.
    act(() => {
      rerender({ a: true, k: 1 })
    })
    expect(result.current).toBe(true)
  })
})
