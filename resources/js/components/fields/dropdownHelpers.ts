import type { MouseEvent as ReactMouseEvent } from 'react'

/**
 * Shared `pt.clearIcon` config for PrimeReact Dropdown / MultiSelect fields.
 *
 * Wires up:
 *  - The package-standard tooltip on hover (`data-pr-tooltip` + position)
 *  - `onMouseDown` / `onClick` with `stopPropagation()` so that clicking the
 *    clear (×) icon does NOT bubble up to the dropdown trigger and immediately
 *    re-open the panel — which was the default PrimeReact behaviour.
 */
export function dropdownClearIconPt(tooltip: string): Record<string, unknown> {
  const stop = (e: ReactMouseEvent) => {
    e.stopPropagation()
  }
  return {
    'data-pr-tooltip': tooltip,
    'data-pr-position': 'top',
    onMouseDown: stop,
    onClick: stop,
  }
}
