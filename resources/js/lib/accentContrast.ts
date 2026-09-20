/**
 * Picks `--martis-accent-contrast` (the text / icon colour painted on top of
 * an accent fill) for a hex the SPA derives a palette from (the per-user
 * `brandColor`): white as long as it reaches the WCAG 3:1 floor for UI
 * components / large text against the accent, a near-black navy below it
 * (lime, cyan, amber, yellow…).
 *
 * Mirrors `Martis\Preferences\AccentContrast` (PHP, used for the SSR custom
 * accent block) and the inline boot script in `app.blade.php`. Keep the
 * three in sync so the UI never flashes between two contrast colours.
 */
export const ACCENT_CONTRAST_LIGHT = '#ffffff'
export const ACCENT_CONTRAST_DARK = '#0b1220'
/** WCAG 1.4.11 / 1.4.3 (large text) minimum contrast ratio. */
export const ACCENT_CONTRAST_MIN_LIGHT_RATIO = 3.0

function rgb(hex: string): [number, number, number] | null {
  let v = hex.trim().replace(/^#/, '')
  if (!/^[0-9a-f]{3,4}$|^[0-9a-f]{6}$|^[0-9a-f]{8}$/i.test(v)) return null
  if (v.length <= 4) v = v[0] + v[0] + v[1] + v[1] + v[2] + v[2]
  return [parseInt(v.slice(0, 2), 16), parseInt(v.slice(2, 4), 16), parseInt(v.slice(4, 6), 16)]
}

function luminance([r, g, b]: [number, number, number]): number {
  const lin = (channel: number) => {
    const c = channel / 255
    return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4
  }
  return 0.2126 * lin(r) + 0.7152 * lin(g) + 0.0722 * lin(b)
}

function ratio(a: number, b: number): number {
  const [hi, lo] = a >= b ? [a, b] : [b, a]
  return (hi + 0.05) / (lo + 0.05)
}

export function accentContrastFor(hex: string): string {
  const accent = rgb(hex)
  if (!accent) return ACCENT_CONTRAST_LIGHT
  return ratio(luminance(accent), luminance([255, 255, 255])) >= ACCENT_CONTRAST_MIN_LIGHT_RATIO
    ? ACCENT_CONTRAST_LIGHT
    : ACCENT_CONTRAST_DARK
}
