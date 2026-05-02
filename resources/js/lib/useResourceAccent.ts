import { useEffect } from 'react'

const HEX_RE = /^#([0-9a-f]{3}|[0-9a-f]{6})$/i

/**
 * Override the panel's accent colour while a resource view is mounted.
 *
 * Matches the contract documented for `Resource::accentColor()`:
 *
 *   - A built-in accent name (`martis | blue | teal | violet | amber`,
 *     or any custom one the host theme registered) is written to the
 *     `data-accent` attribute on `<html>`.
 *   - A hex string is written as an inline `--martis-accent` property
 *     on `<html>` (wins over the `[data-accent]` selector for the
 *     duration of the override).
 *   - `null` / `undefined` is a no-op, keeping the user's global
 *     preference active.
 *
 * On unmount (or accent change), the hook restores whatever the
 * `<html>` element had before — including any preference written by
 * `PreferencesContext`. A tiny wins ref tracks the last value we
 * applied so an accidental tab-switch doesn't double-restore.
 */
export function useResourceAccent(accent: string | null | undefined): void {
  useEffect(() => {
    if (!accent) return

    const root = document.documentElement
    const previousAccent = root.getAttribute('data-accent')
    const previousInline = root.style.getPropertyValue('--martis-accent')

    if (HEX_RE.test(accent)) {
      // Hex value — set as inline custom property to override the
      // [data-accent] selector for the duration of this view.
      root.style.setProperty('--martis-accent', accent)
    } else {
      // Named accent — flip the data-accent attribute. CSS rules in
      // the bundled theme map known names to their token sets.
      root.setAttribute('data-accent', accent)
    }

    return () => {
      if (HEX_RE.test(accent)) {
        if (previousInline) {
          root.style.setProperty('--martis-accent', previousInline)
        } else {
          root.style.removeProperty('--martis-accent')
        }
      } else {
        if (previousAccent) {
          root.setAttribute('data-accent', previousAccent)
        } else {
          root.removeAttribute('data-accent')
        }
      }
    }
  }, [accent])
}
