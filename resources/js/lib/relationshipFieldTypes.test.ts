import { describe, it, expect } from 'vitest'
import { STANDALONE_RELATIONSHIP_TYPES, isStandaloneRelationship } from './relationshipFieldTypes'

describe('STANDALONE_RELATIONSHIP_TYPES', () => {
  it('includes the many-to-many pair so they never render as squeezed scalar rows', () => {
    // The exact drift that shipped belongs_to_many/morph_to_many as scalar
    // rows in the drawer while the standard detail page rendered them right.
    expect(STANDALONE_RELATIONSHIP_TYPES.has('belongs_to_many')).toBe(true)
    expect(STANDALONE_RELATIONSHIP_TYPES.has('morph_to_many')).toBe(true)
  })

  it('covers the has/morph family including the OfMany and Through variants', () => {
    for (const type of [
      'has_many',
      'has_many_through',
      'has_one',
      'has_one_of_many',
      'has_one_through',
      'morph_one',
      'morph_one_of_many',
      'morph_many',
    ]) {
      expect(isStandaloneRelationship(type)).toBe(true)
    }
  })

  it('does not treat scalar, inverse, or layout types as standalone panels', () => {
    // belongs_to / morph_to are the singular inverse relations — they render
    // as a scalar dropdown, not a full-width panel.
    for (const type of ['text', 'belongs_to', 'morph_to', 'panel', 'section', 'tab_group']) {
      expect(isStandaloneRelationship(type)).toBe(false)
    }
  })
})
