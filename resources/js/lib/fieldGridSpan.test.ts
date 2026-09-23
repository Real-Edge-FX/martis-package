import { describe, expect, it } from 'vitest'
import { fieldGridSpanStyle, fieldGridStyle, resolveFieldGridSpan } from './fieldGridSpan'

// The form and detail field grids (Section, Panel and Tab bodies) honour
// `colSpan()` / `colSpanMd()` / `colSpanLg()` as a mobile-first cascade from
// `md` upward; below `md` the CSS gives every field the full row (REA-1292).
// The resolver owns the fallback chain and the clamp to the grid's tracks;
// the breakpoints themselves live in `.martis-field-grid` (martis.css).
describe('resolveFieldGridSpan', () => {
  it('uses colSpan for every tier when no responsive span is declared', () => {
    expect(resolveFieldGridSpan({ colSpan: 6 })).toEqual({ base: 6, md: 6, lg: 6 })
  })

  it('takes the full row when no span is declared', () => {
    expect(resolveFieldGridSpan({})).toEqual({ base: 12, md: 12, lg: 12 })
    expect(resolveFieldGridSpan({ colSpan: null }, 3)).toEqual({ base: 3, md: 3, lg: 3 })
  })

  it('cascades colSpanMd into lg when colSpanLg is not declared', () => {
    expect(resolveFieldGridSpan({ colSpan: 12, colSpanMd: 6 })).toEqual({ base: 12, md: 6, lg: 6 })
  })

  it('lets colSpanLg override the md value', () => {
    expect(resolveFieldGridSpan({ colSpan: 12, colSpanMd: 6, colSpanLg: 4 })).toEqual({ base: 12, md: 6, lg: 4 })
    expect(resolveFieldGridSpan({ colSpan: 12, colSpanLg: 4 })).toEqual({ base: 12, md: 12, lg: 4 })
  })

  it('treats null tiers as undeclared, as Field::toArray() serialises them', () => {
    expect(resolveFieldGridSpan({ colSpan: 6, colSpanMd: null, colSpanLg: null })).toEqual({ base: 6, md: 6, lg: 6 })
  })

  it('clamps every tier into the grid, so a span never adds implicit tracks', () => {
    // Field::colSpan() defaults to 12: in a Section::columns(3) grid that is
    // the full row, not twelve tracks that squeeze the three real columns.
    expect(resolveFieldGridSpan({ colSpan: 12 }, 3)).toEqual({ base: 3, md: 3, lg: 3 })
    expect(resolveFieldGridSpan({ colSpan: 1, colSpanMd: 2, colSpanLg: 6 }, 3)).toEqual({ base: 1, md: 2, lg: 3 })
    expect(resolveFieldGridSpan({ colSpan: 0, colSpanMd: 13, colSpanLg: -2 })).toEqual({ base: 1, md: 12, lg: 1 })
    expect(resolveFieldGridSpan({ colSpan: 4.6 })).toEqual({ base: 5, md: 5, lg: 5 })
  })
})

describe('fieldGridSpanStyle', () => {
  it('carries the resolved tiers as the custom properties martis.css reads', () => {
    expect(fieldGridSpanStyle({ colSpan: 12, colSpanMd: 6, colSpanLg: 4 })).toEqual({
      '--martis-field-span': '12',
      '--martis-field-span-md': '6',
      '--martis-field-span-lg': '4',
    })
  })

  it('resolves the tiers against the grid it is placed in', () => {
    expect(fieldGridSpanStyle({ colSpan: 12, colSpanLg: 2 }, 3)).toEqual({
      '--martis-field-span': '3',
      '--martis-field-span-md': '3',
      '--martis-field-span-lg': '2',
    })
  })

  it('never emits an inline grid-column, so the media queries own placement', () => {
    expect(Object.keys(fieldGridSpanStyle({ colSpan: 6 }))).not.toContain('gridColumn')
  })
})

describe('fieldGridStyle', () => {
  it('hands the grid its track count', () => {
    expect(fieldGridStyle(3)).toEqual({ '--martis-field-columns': '3' })
    expect(fieldGridStyle()).toEqual({ '--martis-field-columns': '12' })
    expect(fieldGridStyle(0)).toEqual({ '--martis-field-columns': '1' })
  })

  it('never emits an inline grid-template-columns, so the stylesheet owns the tracks', () => {
    expect(Object.keys(fieldGridStyle(3))).not.toContain('gridTemplateColumns')
  })
})
