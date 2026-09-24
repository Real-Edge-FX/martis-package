/**
 * Deterministic avatar colour lookup.
 *
 * Two users with the same name (or seed) always get the same colour.
 * The 16 hues are declared as `--martis-avatar-1..16` in `martis.css`
 * and stay identical across light/dark themes so a person's avatar
 * doesn't shift colour when the user toggles the theme. A theme can
 * redefine them.
 *
 * The server picks the slot of the avatars it sends (the `palette` of the
 * Avatar / UiAvatar fields, the user's `avatar_palette`) with the same
 * hash as `avatarPaletteSlot()` (`Martis\Support\Initials`), so a seed
 * lands on the same slot on either side.
 */

export const AVATAR_PALETTE_SIZE = 16

/** The neutral slot (slate), for an empty seed or an unknown slot. */
const NEUTRAL_SLOT = AVATAR_PALETTE_SIZE

// Cheap, stable string hash. djb2 variant — sufficient for picking one
// of 16 buckets without pulling in a crypto library.
function hashString(seed: string): number {
  let hash = 5381
  for (let i = 0; i < seed.length; i++) {
    hash = ((hash << 5) + hash + seed.charCodeAt(i)) | 0
  }
  return Math.abs(hash)
}

/** Whether `value` is a palette slot: an integer from 1 to 16. */
export function isAvatarPaletteSlot(value: unknown): value is number {
  return typeof value === 'number' && Number.isInteger(value) && value >= 1 && value <= AVATAR_PALETTE_SIZE
}

/** The palette slot (1..16) of a seed. An empty seed gets the neutral slot 16. */
export function avatarPaletteSlot(seed: string | null | undefined): number {
  if (!seed) return NEUTRAL_SLOT
  return (hashString(seed) % AVATAR_PALETTE_SIZE) + 1
}

/**
 * The CSS colour of a palette slot, `var(--martis-avatar-N)`, ready for a
 * `style.backgroundColor`. Anything that is not a slot gets the neutral one.
 */
export function avatarPaletteColor(slot: number | null | undefined): string {
  return `var(--martis-avatar-${isAvatarPaletteSlot(slot) ? slot : NEUTRAL_SLOT})`
}

/**
 * Map a seed (name, email, slug, anything) to one of the 16 avatar
 * hues. Returns the CSS `var(--martis-avatar-N)` reference so callers
 * can drop it directly into a `style.backgroundColor`.
 */
export function avatarColorForSeed(seed: string | null | undefined): string {
  return avatarPaletteColor(avatarPaletteSlot(seed))
}

/**
 * The hex value of a palette slot, read from the document root so it
 * follows the active theme without touching the CSS at runtime. Returns
 * `null` outside a browser or when the token is unset.
 */
export function avatarHexForSlot(slot: number | null | undefined): string | null {
  if (typeof window === 'undefined') return null
  const value = getComputedStyle(document.documentElement)
    .getPropertyValue(`--martis-avatar-${isAvatarPaletteSlot(slot) ? slot : NEUTRAL_SLOT}`)
    .trim()
  return value || null
}

/**
 * The hex value of the avatar hue of a seed. Useful when a caller needs
 * the literal colour (e.g. computing readable text colour) instead of the
 * CSS variable. Returns `null` outside a browser or for an empty seed.
 */
export function avatarHexForSeed(seed: string | null | undefined): string | null {
  if (typeof window === 'undefined' || !seed) return null
  return avatarHexForSlot(avatarPaletteSlot(seed))
}

/**
 * Pick a legible text colour (black or white) for the given hex bg using
 * the WCAG luminance approximation. Avoids hard-coding white on every
 * swatch so light brand colours (yellow, cyan) remain readable.
 */
export function readableTextColor(hex: string | null | undefined): string {
  if (!hex) return '#fff'
  const h = hex.replace('#', '')
  if (h.length !== 6) return '#fff'
  const r = parseInt(h.substring(0, 2), 16)
  const g = parseInt(h.substring(2, 4), 16)
  const b = parseInt(h.substring(4, 6), 16)
  const luminance = (0.299 * r + 0.587 * g + 0.114 * b) / 255
  return luminance > 0.6 ? '#0f172a' : '#ffffff'
}

/** Background and text colour of an initials avatar painted with a palette slot. */
export function avatarPaletteStyle(slot: number | null | undefined): { backgroundColor: string; color: string } {
  return {
    backgroundColor: avatarPaletteColor(slot),
    color: readableTextColor(avatarHexForSlot(slot)),
  }
}

/**
 * Background and text colour of the initials circle of an Avatar /
 * UiAvatar payload. The server's `palette` slot paints it with the theme
 * token; a `color` without a slot is a `colorFrom()` value; a payload with
 * neither gets the slot of its seed.
 */
export function initialsAvatarStyle(payload: {
  palette?: unknown
  color?: string | null
  seed?: string | null
}): { backgroundColor: string; color: string } {
  if (isAvatarPaletteSlot(payload.palette)) return avatarPaletteStyle(payload.palette)
  if (payload.color) return { backgroundColor: payload.color, color: readableTextColor(payload.color) }
  return avatarPaletteStyle(avatarPaletteSlot(payload.seed))
}
