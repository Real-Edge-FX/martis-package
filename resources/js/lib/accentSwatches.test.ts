import { afterEach, describe, expect, it } from 'vitest'
import { resolveAccentSwatchColor } from './accentSwatches'

// The Preferences accent picker used to paint each swatch from a literal hex
// table, so a branded theme that redefines `--martis-accent` (or a custom
// accent a theme overrides per mode) never matched the colour the UI is
// actually painted with. The swatch colour is now read from the stylesheets
// exactly as the cascade would resolve it for `html[data-accent="<key>"]`
// in the current mode, falling back to the literal.

function addSheet(css: string): HTMLStyleElement {
  const style = document.createElement('style')
  style.textContent = css
  document.head.appendChild(style)
  return style
}

afterEach(() => {
  document.querySelectorAll('style').forEach((s) => s.remove())
})

describe('resolveAccentSwatchColor', () => {
  it('falls back to the literal when no stylesheet declares the accent', () => {
    expect(resolveAccentSwatchColor('blue', 'dark', '#3B82F6')).toBe('#3B82F6')
  })

  it('reads a bundled accent per mode from the package rules', () => {
    addSheet(`
      html.dark[data-accent="blue"] { --martis-accent: #3B82F6; --martis-accent-hover: #2563EB; }
      html:not(.dark)[data-accent="blue"] { --martis-accent: #2563EB; }
    `)
    expect(resolveAccentSwatchColor('blue', 'dark', '#000')).toBe('#3B82F6')
    expect(resolveAccentSwatchColor('blue', 'light', '#000')).toBe('#2563EB')
  })

  it('resolves the default "martis" accent from the root blocks a theme redefines', () => {
    // Package defaults…
    addSheet(`
      :root { --martis-accent: #4F7BF9; }
      html:not(.dark) { --martis-accent: #3B6AF0; }
    `)
    // …then the consumer theme (stub selectors), loaded after app.css.
    addSheet(`
      :root { --martis-accent: #0070F0; }
      html[data-theme="light"] { --martis-accent: #006CE7; }
    `)
    expect(resolveAccentSwatchColor('martis', 'dark', '#4F7BF9')).toBe('#0070F0')
    expect(resolveAccentSwatchColor('martis', 'light', '#4F7BF9')).toBe('#006CE7')
  })

  it('lets a later stylesheet win for the same accent and mode (cascade order)', () => {
    addSheet(`html.dark[data-accent="teal"] { --martis-accent: #14B8A6; }`)
    addSheet(`html[data-theme="dark"][data-accent="teal"] { --martis-accent: #00837A; }`)
    expect(resolveAccentSwatchColor('teal', 'dark', '#000')).toBe('#00837A')
  })

  it('reads a custom accent from the inline block and a per-mode theme override of it', () => {
    addSheet(`html[data-accent="imoray-teal"] { --martis-accent: #14b8a6; --martis-accent-hover: color-mix(in srgb, #14b8a6 88%, black); }`)
    expect(resolveAccentSwatchColor('imoray-teal', 'dark', '#000')).toBe('#14b8a6')
    expect(resolveAccentSwatchColor('imoray-teal', 'light', '#000')).toBe('#14b8a6')

    addSheet(`html[data-theme="light"][data-accent="imoray-teal"] { --martis-accent: #008077; }`)
    expect(resolveAccentSwatchColor('imoray-teal', 'light', '#000')).toBe('#008077')
    expect(resolveAccentSwatchColor('imoray-teal', 'dark', '#000')).toBe('#14b8a6')
  })

  it('ignores rules scoped to descendants (resource accents) and selector lists that do not target html', () => {
    addSheet(`
      html.dark [data-resource-accent="violet"] { --martis-accent: #ff0000; }
      .martis-card[data-accent="violet"] { --martis-accent: #00ff00; }
      html.dark[data-accent="violet"], html.dark [data-resource-accent="violet"] { --martis-accent: #8B5CF6; }
    `)
    expect(resolveAccentSwatchColor('violet', 'dark', '#000')).toBe('#8B5CF6')
  })

  it('does not let another accent or the root blocks leak into a bundled key', () => {
    addSheet(`
      :root { --martis-accent: #4F7BF9; }
      html.dark[data-accent="blue"] { --martis-accent: #3B82F6; }
    `)
    expect(resolveAccentSwatchColor('amber', 'dark', '#F59E0B')).toBe('#F59E0B')
  })
})
