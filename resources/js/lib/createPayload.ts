import type { FieldDefinition } from '@/types'
import { fieldTypesByAttribute, reduceBelongsTo } from '@/lib/formPayload'

/**
 * The values a create form (the create page, the create drawer and the inline
 * create modal) submits for a new record. `fields` is the form's field
 * definitions (containers included): a value is shaped by its field's type,
 * never by its own shape, so a KeyValue or a JSON field whose map has `id` and
 * `title` keys is sent as it is.
 *
 * - A BelongsTo value (`{ id, title }`, what a form holds once a record is
 *   picked, and what a create launched from a relationship panel pre-fills
 *   for the parent) is reduced to its id, as an update does (see
 *   `updatePayload`), plus `<attribute>_trashed: true` for a soft-deleted
 *   target. A consumer rule such as `Rule::exists('contacts', 'id')`
 *   then receives a scalar: an array makes `exists` a multi-value `whereIn`
 *   (a 500 on PostgreSQL, a false 422 elsewhere).
 * - A MorphTo value keeps its target map (`{ type, id, title, resourceType }`
 *   as copied, `{ resourceType, id, title }` once a record is picked): the id
 *   alone does not say which model it points to.
 * - A file value is kept as it is. `updatePayload` leaves out a stored file
 *   (`{ url, ... }`) so the server keeps it, but a new record has no stored
 *   file to keep.
 * - Any other value, and a value whose attribute has no definition, is untouched.
 */
export function createPayload(
  values: Record<string, unknown>,
  fields: readonly FieldDefinition[],
): Record<string, unknown> {
  const types = fieldTypesByAttribute(fields)
  const payload: Record<string, unknown> = {}

  for (const [key, val] of Object.entries(values)) {
    if (types.get(key) === 'belongs_to') {
      reduceBelongsTo(payload, key, val)
      continue
    }

    payload[key] = val
  }

  return payload
}
