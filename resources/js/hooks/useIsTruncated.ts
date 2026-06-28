import { useEffect, useLayoutEffect, useRef, useState } from 'react'

/**
 * Reports whether an element's text content overflows its visible box.
 *
 * Pattern: pair this hook with a `text-overflow: ellipsis` span. When
 * the rendered label is wider than the container (`scrollWidth >
 * clientWidth`), the consumer can surface the full label via a
 * PrimeReact tooltip (`data-pr-tooltip={label}`) instead of leaving
 * the user staring at a truncated `…` with no way to read the rest.
 *
 * Sub-pixel rounding: real-world layouts return values that differ
 * by < 1 px even when visually identical (browsers + fractional
 * device-pixel ratios + Tailwind line-height combinations). A `+ 1`
 * tolerance keeps the tooltip from flickering on otherwise-fitting
 * labels.
 *
 * SSR / no-ResizeObserver fallback: when `ResizeObserver` is not
 * available (jsdom by default, very old browsers), the hook still
 * runs one measurement after the initial layout pass and returns
 * the snapshot.
 */
export function useIsTruncated<T extends HTMLElement>(): readonly [React.RefObject<T>, boolean] {
  const ref = useRef<T | null>(null) as React.RefObject<T>
  const [truncated, setTruncated] = useState(false)

  const measure = () => {
    const el = ref.current
    if (!el) return
    setTruncated(el.scrollWidth > el.clientWidth + 1)
  }

  useLayoutEffect(() => {
    measure()
  })

  useEffect(() => {
    const el = ref.current
    if (!el) return
    if (typeof ResizeObserver === 'undefined') return

    const observer = new ResizeObserver(() => measure())
    observer.observe(el)
    return () => observer.disconnect()
  }, [])

  return [ref, truncated] as const
}
