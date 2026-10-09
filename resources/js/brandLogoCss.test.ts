import { describe, expect, it } from 'vitest'
import { readFileSync } from 'node:fs'
import path from 'node:path'

// A horizontal lockup wider than the logo box (a 220×40 one at the default
// 40px menu height in the 240px sidebar, whose box is capped at 208px) must
// scale down to the box, not be cut by its `overflow: hidden` (v2.6.0).
// The rendered behaviour is checked in a browser; this pins the rule.

const css = readFileSync(path.resolve(process.cwd(), 'resources/css/martis.css'), 'utf8')

function declarations(selector: string): string {
  const start = css.indexOf(`${selector} {`)
  expect(start, `${selector} is in martis.css`).toBeGreaterThan(-1)
  return css.slice(start, css.indexOf('}', start))
}

describe('the brand lockup in the menu', () => {
  it.each([
    '.martis-sb-logo[data-mode="logo"] .martis-sb-logo-mark img',
    '.martis-topnav-brand[data-mode="logo"] .martis-sb-logo-mark img',
  ])('%s scales down to the width of its box', (selector) => {
    const rule = declarations(selector)

    expect(rule).toContain('max-width: 100%;')
    expect(rule).toContain('object-fit: contain;')
  })

  it('keeps the left-hand side of a lockup in the collapsed rail, unscaled', () => {
    const rule = declarations('.martis-sb[data-collapsed="true"] .martis-sb-logo[data-mode="logo"] .martis-sb-logo-mark img')

    expect(rule).toContain('max-width: none;')
  })
})
