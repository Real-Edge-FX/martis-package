/**
 * Platform detection for UI that surfaces keyboard shortcuts.
 *
 * The command palette, modal close hints, and any future cheat-sheet need
 * to render the correct modifier for the host OS — `⌘` on macOS, `Ctrl`
 * everywhere else. Detection is cached once per page load because
 * `navigator.platform` never changes between renders and the lookup runs
 * on every key-hint render otherwise.
 */

let cached: boolean | null = null

/** Returns `true` when the current platform is macOS (or iPad / iPhone). */
export function isMacPlatform(): boolean {
  if (cached !== null) return cached
  if (typeof navigator === 'undefined') {
    cached = false
    return cached
  }

  const ua = navigator.userAgent ?? ''
  const platform = navigator.platform ?? ''
  cached = /Mac|iPad|iPhone|iPod/.test(platform) || /Mac OS X/.test(ua)
  return cached
}

/** Human-friendly "Cmd/Ctrl + X" label for a command that lives on the
 *  platform's primary modifier. Mac renders with the apple glyph; other
 *  platforms spell it out so the cheat-sheet stays readable. */
export function modKeyLabel(letter: string): string {
  const upper = letter.toUpperCase()
  return isMacPlatform() ? `\u2318${upper}` : `Ctrl ${upper}`
}
