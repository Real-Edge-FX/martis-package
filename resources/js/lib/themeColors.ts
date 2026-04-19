/**
 * Resolve CSS custom properties at runtime.
 *
 * Chart.js (and other canvas-based libraries) cannot consume CSS variables
 * directly — they need resolved color strings. This helper reads the
 * computed value of any var(--martis-*) at runtime so charts respect
 * the active theme.
 */

/**
 * Resolve a single CSS variable to its computed value.
 * Returns the fallback if not in browser context or var is missing.
 */
export function cssVar(name: string, fallback = ''): string {
  if (typeof window === 'undefined') return fallback
  const value = getComputedStyle(document.documentElement)
    .getPropertyValue(name)
    .trim()
  return value || fallback
}

/**
 * Read multiple CSS variables at once.
 */
export function cssVars(names: string[]): string[] {
  return names.map((n) => cssVar(n))
}

/**
 * Resolve a color value: if it starts with `var(--...)`, resolve it;
 * otherwise return as-is. Useful when accepting user-provided colors that
 * may be either CSS vars or literal hex/rgb.
 */
export function resolveColor(input: string | null | undefined, fallback = ''): string {
  if (!input) return fallback
  const trimmed = input.trim()
  if (trimmed.startsWith('var(')) {
    const match = trimmed.match(/var\((--[^,)]+)/)
    if (match) {
      return cssVar(match[1], fallback)
    }
  }
  return trimmed
}

/**
 * Get the default 10-color chart palette resolved from CSS vars.
 */
export function chartPalette(): string[] {
  return cssVars([
    '--martis-chart-1',
    '--martis-chart-2',
    '--martis-chart-3',
    '--martis-chart-4',
    '--martis-chart-5',
    '--martis-chart-6',
    '--martis-chart-7',
    '--martis-chart-8',
    '--martis-chart-9',
    '--martis-chart-10',
  ])
}

/**
 * Get the muted text color (used for chart axis labels).
 */
export function mutedTextColor(): string {
  return cssVar('--martis-text-muted', '#94a3b8')
}

/**
 * Get the accent color.
 */
export function accentColor(): string {
  return cssVar('--martis-accent', '#6366f1')
}
