import { useEffect } from 'react'
import { useTranslation } from 'react-i18next'
import { config } from '@/lib/config'

/**
 * Update `document.title` while a component is mounted. Pass a single
 * segment (e.g. "Clients", "Edit Invoice") and the hook formats it as
 * `"{segment} · {brand}"`. Pass `null` to restore the Martis default
 * ("{brand} — Admin Control" in English; translated per locale).
 *
 * Call this from every top-level page/layout so the tab title tracks the
 * current view across client-side navigation — the server-side title set
 * by the blade template only fires on full page loads.
 *
 * Pass `{ pending: true }` while the segment is still loading (v2.19.0):
 * the title is left as it is (the server's title on a full load, the
 * previous page's after a client-side navigation) instead of flashing the
 * generic default.
 */
export function usePageTitle(segment: string | null | undefined, options: { pending?: boolean } = {}): void {
  const { t } = useTranslation('navigation')
  const pending = options.pending === true && !(segment && segment.trim() !== '')

  useEffect(() => {
    if (pending) return
    const brand = (config.brand ?? 'Martis').toString()
    const previous = document.title

    const next = segment && segment.trim() !== ''
      ? `${segment} · ${brand}`
      : t('page_title_default', { brand, defaultValue: `${brand} — Admin Control` })

    document.title = next

    return () => {
      // Restore the previous title when the component unmounts, so
      // stacked overlays (drawers, modals) don't leave stale segments
      // in the tab bar after they close.
      document.title = previous
    }
  }, [segment, pending, t])
}
