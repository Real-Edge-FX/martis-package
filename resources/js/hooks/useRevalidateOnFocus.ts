import { useEffect } from 'react'

/**
 * Call `onRevalidate` whenever the tab becomes visible again or the window
 * regains focus — for Tools that fetch data manually (without react-query,
 * which already revalidates on focus). Lets a custom Tool page refresh its
 * data when the operator returns to a backgrounded Martis tab, matching the
 * cross-session freshness Resource lists get for free. No dependencies.
 */
export function useRevalidateOnFocus(onRevalidate: () => void): void {
  useEffect(() => {
    function onVisible() {
      if (document.visibilityState === 'visible') onRevalidate()
    }
    function onFocus() { onRevalidate() }
    document.addEventListener('visibilitychange', onVisible)
    window.addEventListener('focus', onFocus)
    return () => {
      document.removeEventListener('visibilitychange', onVisible)
      window.removeEventListener('focus', onFocus)
    }
  }, [onRevalidate])
}
