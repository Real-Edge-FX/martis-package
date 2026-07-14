/**
 * Relationship field types that render as their own full-width panel on a
 * detail surface — each with its own heading and action buttons (search /
 * attach / paginated table). They must NOT be wrapped in the scalar
 * `dl`/`dt`/`dd` grid: that gives them a ~140px label gutter (squeezing the
 * panel) and duplicates the heading (once from the `<dt>`, once from the
 * panel's own `<h3>`).
 *
 * Shared by ResourceDetail (the standard detail page) and DrawerDetail (the
 * detail drawer) so the two partition surfaces cannot drift. A drift is
 * exactly what shipped `belongs_to_many` / `morph_to_many` as squeezed scalar
 * rows in the drawer while the standard page rendered them correctly: the
 * drawer's copy of this set had fallen behind and omitted both.
 *
 * Covers the whole has/morph family including the OfMany and Through variants,
 * plus the many-to-many pair (belongs_to_many, morph_to_many).
 */
export const STANDALONE_RELATIONSHIP_TYPES: ReadonlySet<string> = new Set([
  'has_many',
  'has_many_through',
  'has_one',
  'has_one_of_many',
  'has_one_through',
  'morph_one',
  'morph_one_of_many',
  'morph_many',
  'belongs_to_many',
  'morph_to_many',
])

/** Whether a field type renders as a standalone full-width relationship panel. */
export function isStandaloneRelationship(type: string): boolean {
  return STANDALONE_RELATIONSHIP_TYPES.has(type)
}
