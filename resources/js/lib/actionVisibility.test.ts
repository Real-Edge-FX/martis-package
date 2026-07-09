import { describe, it, expect } from 'vitest'
import { filterIndexActions, filterInlineActions } from './actionVisibility'

// Exercises the REAL production partitioning used by ResourceIndex and
// ResourceLens (not a re-implemented copy), so `showInline()`'s additive
// contract can't silently regress again.

const ACTIONS = [
  { uriKey: 'a1', name: 'Index Action', showOnIndex: true, showInline: false },
  { uriKey: 'a2', name: 'Detail Action', showOnIndex: false, showInline: false },
  { uriKey: 'a3', name: 'Inline Only', showOnIndex: false, showInline: true }, // onlyInline()
  { uriKey: 'a4', name: 'Everywhere', showOnIndex: true, showInline: true }, // showInline() (additive)
]

describe('filterIndexActions', () => {
  it('includes every showOnIndex action, including additive showInline ones', () => {
    // "Everywhere" has showOnIndex=true + showInline=true → must appear in the
    // dropdown (showInline is additive). "Inline Only" (onlyInline, showOnIndex
    // false) must NOT. This is the exact case the `&& !showInline` bug broke.
    expect(filterIndexActions(ACTIONS).map((a) => a.name)).toEqual(['Index Action', 'Everywhere'])
  })
})

describe('filterInlineActions', () => {
  it('includes every showInline action', () => {
    expect(filterInlineActions(ACTIONS).map((a) => a.name)).toEqual(['Inline Only', 'Everywhere'])
  })
})
