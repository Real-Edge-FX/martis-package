/**
 * Resolve the colour a swatch in the Preferences accent picker should show
 * for an accent key, by reading the stylesheets the way the cascade would
 * for `html[data-accent="<key>"]` in the current mode.
 *
 * The bundled accents live in `martis.css` (`html.dark[data-accent="x"]` /
 * `html:not(.dark)[data-accent="x"]`), a consumer theme redefines them with
 * the stub selectors (`html[data-theme="dark"][data-accent="x"]`), custom
 * accents come from the inline block `app.blade.php` emits
 * (`html[data-accent="x"]`), and the default "martis" accent is whatever the
 * root blocks declare (`:root`, `html:not(.dark)`, `html[data-theme="light"]`
 * …), which is exactly what a branded theme overrides. Walking the CSSOM is
 * side-effect free: toggling `data-accent` on `<html>` to read a computed
 * value would start colour transitions on every accent-painted element.
 */
export type SwatchMode = 'dark' | 'light'

const DEFAULT_ACCENT_KEY = 'martis'

/** `html` / `:root` followed only by mode qualifiers and, optionally, the accent attribute. */
const QUALIFIER = String.raw`(?:\.dark|:not\(\.dark\)|\[data-theme=["']?(?:dark|light)["']?\])`
const ROOT_SELECTOR = new RegExp(String.raw`^(?::root|html)(${QUALIFIER})*$`)

function accentSelector(key: string): RegExp {
  const escaped = key.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
  return new RegExp(String.raw`^(?::root|html)(${QUALIFIER})*\[data-accent=["']?${escaped}["']?\](${QUALIFIER})*$`)
}

/** Which mode a selector part is confined to, or null when it applies to both. */
function modeOf(part: string): SwatchMode | null {
  if (/:not\(\.dark\)|\[data-theme=["']?light["']?\]/.test(part)) return 'light'
  if (/\.dark|\[data-theme=["']?dark["']?\]/.test(part)) return 'dark'
  return null
}

/** Split a selector list on top-level commas (attribute selectors may contain commas). */
function splitSelectorList(selectorText: string): string[] {
  const parts: string[] = []
  let depth = 0
  let current = ''
  for (const ch of selectorText) {
    if (ch === '(' || ch === '[') depth++
    if (ch === ')' || ch === ']') depth--
    if (ch === ',' && depth === 0) {
      parts.push(current.trim())
      current = ''
      continue
    }
    current += ch
  }
  if (current.trim()) parts.push(current.trim())
  return parts
}

function* styleRules(rules: CSSRuleList, view: Window): Generator<CSSStyleRule> {
  const w = view as unknown as typeof globalThis
  for (const rule of Array.from(rules)) {
    if (rule instanceof w.CSSStyleRule) {
      yield rule
      continue
    }
    if (rule instanceof w.CSSMediaRule) {
      if (view.matchMedia?.(rule.conditionText)?.matches) yield* styleRules(rule.cssRules, view)
      continue
    }
    if (typeof w.CSSSupportsRule !== 'undefined' && rule instanceof w.CSSSupportsRule) {
      if (w.CSS?.supports?.(rule.conditionText)) yield* styleRules(rule.cssRules, view)
    }
  }
}

export function resolveAccentSwatchColor(
  key: string,
  mode: SwatchMode,
  fallback: string,
  doc: Document = document,
): string {
  const view = doc.defaultView
  if (!view) return fallback

  const dedicated = accentSelector(key)
  let dedicatedValue: string | null = null
  let rootValue: string | null = null

  for (const sheet of Array.from(doc.styleSheets)) {
    let rules: CSSRuleList
    try {
      rules = (sheet as CSSStyleSheet).cssRules
    } catch {
      continue // cross-origin stylesheet: not ours
    }
    for (const rule of styleRules(rules, view)) {
      const value = rule.style.getPropertyValue('--martis-accent').trim()
      if (!value) continue
      for (const part of splitSelectorList(rule.selectorText)) {
        const partMode = modeOf(part)
        if (partMode !== null && partMode !== mode) continue
        if (dedicated.test(part)) dedicatedValue = value
        else if (key === DEFAULT_ACCENT_KEY && ROOT_SELECTOR.test(part)) rootValue = value
      }
    }
  }

  return dedicatedValue ?? rootValue ?? fallback
}

/** The mode the accent rules currently apply under. */
export function currentSwatchMode(doc: Document = document): SwatchMode {
  return doc.documentElement.classList.contains('dark') ? 'dark' : 'light'
}
