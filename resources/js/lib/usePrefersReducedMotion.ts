import { useEffect, useState } from 'react'

/**
 * Reactive reduced-motion preference. Returns `true` when either:
 *   - the OS preference is set (`@media (prefers-reduced-motion: reduce)`)
 *   - the Martis user preference toggle is on
 *     (`html[data-reduced-motion="true"]`, written by `PreferencesContext`)
 *
 * Re-evaluates whenever either signal changes so consumers can pause /
 * resume motion at runtime without remounting. Guards against SSR by
 * defaulting to `false` until the first effect tick.
 */
export function usePrefersReducedMotion(): boolean {
  const [reduced, setReduced] = useState<boolean>(false)

  useEffect(() => {
    if (typeof window === 'undefined') return

    const mediaQuery = window.matchMedia('(prefers-reduced-motion: reduce)')
    const root = document.documentElement

    const evaluate = () => {
      const fromMedia = mediaQuery.matches
      const fromAttr = root.getAttribute('data-reduced-motion') === 'true'
      setReduced(fromMedia || fromAttr)
    }

    evaluate()

    mediaQuery.addEventListener('change', evaluate)

    // Re-evaluate when PreferencesContext flips the data attribute.
    const observer = new MutationObserver((mutations) => {
      for (const m of mutations) {
        if (m.type === 'attributes' && m.attributeName === 'data-reduced-motion') {
          evaluate()
          break
        }
      }
    })
    observer.observe(root, { attributes: true, attributeFilter: ['data-reduced-motion'] })

    return () => {
      mediaQuery.removeEventListener('change', evaluate)
      observer.disconnect()
    }
  }, [])

  return reduced
}
