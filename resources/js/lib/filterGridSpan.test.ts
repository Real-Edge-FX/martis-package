import { describe, expect, it } from 'vitest'
import { filterGridSpanStyle, resolveFilterGridSpan } from './filterGridSpan'

// The filter panel places each filter on a 12-column grid from `md` upward
// (`Filter::span()`, else a quarter row, half a row for a date range); below
// `md` the CSS gives every filter the full row. The breakpoints live in
// `.martis-filter-grid` (martis.css), never in an inline `grid-column`.
describe('resolveFilterGridSpan', () => {
  it('uses the declared span', () => {
    expect(resolveFilterGridSpan({ filterType: 'select', span: 4 })).toBe(4)
    expect(resolveFilterGridSpan({ filterType: 'date-range', span: 12 })).toBe(12)
  })

  it('defaults to a quarter row, or half a row for a date range', () => {
    expect(resolveFilterGridSpan({ filterType: 'select' })).toBe(3)
    expect(resolveFilterGridSpan({ filterType: 'boolean', span: null })).toBe(3)
    expect(resolveFilterGridSpan({ filterType: 'date-range', span: null })).toBe(6)
  })

  it('clamps into the 12-column grid', () => {
    expect(resolveFilterGridSpan({ filterType: 'select', span: 0 })).toBe(1)
    expect(resolveFilterGridSpan({ filterType: 'select', span: 13 })).toBe(12)
  })
})

describe('filterGridSpanStyle', () => {
  it('carries the span as the custom property martis.css reads', () => {
    expect(filterGridSpanStyle({ filterType: 'date-range' })).toEqual({ '--martis-filter-span': '6' })
    expect(filterGridSpanStyle({ filterType: 'select', span: 8 })).toEqual({ '--martis-filter-span': '8' })
  })

  it('never emits an inline grid-column, so the media query owns placement', () => {
    expect(Object.keys(filterGridSpanStyle({ filterType: 'select' }))).not.toContain('gridColumn')
  })
})
