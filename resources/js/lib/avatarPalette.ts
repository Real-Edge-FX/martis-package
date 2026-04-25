/**
 * Deterministic avatar colour lookup.
 *
 * Two users with the same name (or seed) always get the same colour.
 * The 16 hues are declared as `--martis-avatar-1..16` in `martis.css`
 * and stay identical across light/dark themes so a person's avatar
 * doesn't shift colour when the user toggles the theme.
 *
 * Used by AvatarField / UiAvatarField when no explicit `color` is
 * supplied by the backend. Backend-supplied colours still win — this
 * is the zero-config fallback.
 */

const AVATAR_HUE_COUNT = 16

// Cheap, stable string hash. djb2 variant — sufficient for picking one
// of 16 buckets without pulling in a crypto library.
function hashString(seed: string): number {
  let hash = 5381
  for (let i = 0; i < seed.length; i++) {
    hash = ((hash << 5) + hash + seed.charCodeAt(i)) | 0
  }
  return Math.abs(hash)
}

/**
 * Map a seed (name, email, slug, anything) to one of the 16 avatar
 * hues. Returns the CSS `var(--martis-avatar-N)` reference so callers
 * can drop it directly into a `style.backgroundColor`.
 */
export function avatarColorForSeed(seed: string | null | undefined): string {
  if (!seed) return 'var(--martis-avatar-16)' // slate fallback
  const index = (hashString(seed) % AVATAR_HUE_COUNT) + 1
  return `var(--martis-avatar-${index})`
}

/**
 * The hex value of an avatar hue at a given 1-indexed slot. Useful when
 * a caller needs the literal colour (e.g. computing readable text
 * colour) instead of the CSS variable. Reads the resolved value from
 * the document root so it follows the active theme without touching
 * the CSS at runtime. Returns `null` when called outside a browser.
 */
export function avatarHexForSeed(seed: string | null | undefined): string | null {
  if (typeof window === 'undefined' || !seed) return null
  const index = (hashString(seed) % AVATAR_HUE_COUNT) + 1
  const value = getComputedStyle(document.documentElement)
    .getPropertyValue(`--martis-avatar-${index}`)
    .trim()
  return value || null
}
