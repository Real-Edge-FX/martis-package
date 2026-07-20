import { useEffect, useRef, useState } from "react"

export interface DelayedFlagOptions {
  /** Once shown, keep the flag true at least this long (anti-flicker). */
  minVisibleMs: number
  /** Force the flag false this long after it turned true (safety cap). */
  maxVisibleMs: number
}

/**
 * Derive a display flag from a raw `active` signal, shaped for a
 * loading overlay:
 *
 *  - turns **true immediately** when `active` becomes true (instant feedback);
 *  - once true, stays true for at least `minVisibleMs` even if `active` clears
 *    sooner, so a fast operation never produces a sub-100ms illegible flash;
 *  - force-clears `maxVisibleMs` after it turned true even if `active` is still
 *    true, so a hung operation can never leave the overlay up forever. The flag
 *    then stays down until `active` cycles false→true again (the cap latch).
 *
 * All timers are cleared on unmount.
 */
export function useDelayedFlag(
  active: boolean,
  { minVisibleMs, maxVisibleMs }: DelayedFlagOptions,
): boolean {
  const [visible, setVisible] = useState(false)
  const shownAtRef = useRef<number | null>(null)
  const cappedRef = useRef(false)
  const timerRef = useRef<ReturnType<typeof setTimeout> | null>(null)

  useEffect(() => {
    const clearTimer = () => {
      if (timerRef.current !== null) {
        clearTimeout(timerRef.current)
        timerRef.current = null
      }
    }

    // A finished switch releases the cap latch so the next one can show.
    if (!active) {
      cappedRef.current = false
    }

    // Rising edge: show now, unless the safety cap already fired for this
    // still-active switch.
    if (active && !visible && shownAtRef.current === null && !cappedRef.current) {
      shownAtRef.current = Date.now()
      setVisible(true)
      return
    }

    if (visible && shownAtRef.current !== null) {
      const shownAt = shownAtRef.current
      const now = Date.now()
      const maxDeadline = shownAt + maxVisibleMs
      // While active, hold until the safety cap. Once inactive, hide at the
      // min-visible deadline (never before it, never past the cap).
      const hideAt = active
        ? maxDeadline
        : Math.min(Math.max(shownAt + minVisibleMs, now), maxDeadline)
      const delay = Math.max(0, hideAt - now)

      const hide = () => {
        timerRef.current = null
        // If we hide while still active, it can only be the safety cap — latch
        // it so the rising-edge branch does not immediately re-show.
        if (active) cappedRef.current = true
        shownAtRef.current = null
        setVisible(false)
      }

      clearTimer()
      if (delay === 0) hide()
      else timerRef.current = setTimeout(hide, delay)
    }
  }, [active, visible, minVisibleMs, maxVisibleMs])

  useEffect(
    () => () => {
      if (timerRef.current !== null) clearTimeout(timerRef.current)
    },
    [],
  )

  return visible
}
