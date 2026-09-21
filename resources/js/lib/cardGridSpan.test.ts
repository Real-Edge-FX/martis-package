import { describe, expect, it } from 'vitest'
import { cardGridSpanStyle, resolveCardGridSpan } from './cardGridSpan'

// The dashboard grid honours `width()` / `widthMd()` / `widthLg()` as a
// mobile-first cascade from `md` upward (below `md` the CSS forces every
// card to the full row). The resolver owns the fallback chain; the
// breakpoints themselves live in `.martis-dashboard-grid` (martis.css).
describe('resolveCardGridSpan', () => {
  it('uses width for every tier when no responsive width is declared', () => {
    expect(resolveCardGridSpan({ width: 6 })).toEqual({ base: 6, md: 6, lg: 6 })
  })

  it('defaults to the PHP default (4) when width is missing', () => {
    expect(resolveCardGridSpan({})).toEqual({ base: 4, md: 4, lg: 4 })
    expect(resolveCardGridSpan({ width: null })).toEqual({ base: 4, md: 4, lg: 4 })
  })

  it('cascades widthMd into lg when widthLg is not declared', () => {
    expect(resolveCardGridSpan({ width: 12, widthMd: 6 })).toEqual({ base: 12, md: 6, lg: 6 })
  })

  it('lets widthLg override the md value', () => {
    expect(resolveCardGridSpan({ width: 12, widthMd: 12, widthLg: 8 })).toEqual({ base: 12, md: 12, lg: 8 })
    expect(resolveCardGridSpan({ width: 12, widthLg: 4 })).toEqual({ base: 12, md: 12, lg: 4 })
  })

  it('treats null tiers as undeclared', () => {
    expect(resolveCardGridSpan({ width: 6, widthMd: null, widthLg: null })).toEqual({ base: 6, md: 6, lg: 6 })
  })

  it('clamps every tier into the 12-column grid', () => {
    expect(resolveCardGridSpan({ width: 0, widthMd: 13, widthLg: -2 })).toEqual({ base: 1, md: 12, lg: 1 })
    expect(resolveCardGridSpan({ width: 4.6 })).toEqual({ base: 5, md: 5, lg: 5 })
  })
})

describe('cardGridSpanStyle', () => {
  it('carries the resolved tiers as the custom properties martis.css reads', () => {
    expect(cardGridSpanStyle({ width: 12, widthMd: 12, widthLg: 8 })).toEqual({
      '--martis-card-span': '12',
      '--martis-card-span-md': '12',
      '--martis-card-span-lg': '8',
    })
  })

  it('never emits an inline grid-column, so the media queries own placement', () => {
    expect(Object.keys(cardGridSpanStyle({ width: 6 }))).not.toContain('gridColumn')
  })
})
