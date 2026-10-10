/**
 * The values a create form (the create page, the create drawer and the inline
 * create modal) submits for a new record.
 *
 * - A BelongsTo value (`{ id, title }`, what a form holds once a record is
 *   picked, and what a create launched from a relationship panel pre-fills
 *   for the parent) is reduced to its id, as an update does (see
 *   `updatePayload`). A consumer rule such as `Rule::exists('contacts', 'id')`
 *   then receives a scalar: an array makes `exists` a multi-value `whereIn`
 *   (a 500 on PostgreSQL, a false 422 elsewhere).
 * - A MorphTo value keeps its target map (`{ type, id, title, resourceType }`
 *   as copied, `{ resourceType, id, title }` once a record is picked): the id
 *   alone does not say which model it points to.
 * - A file value is kept as it is. `updatePayload` leaves out a stored file
 *   (`{ url, ... }`) so the server keeps it, but a new record has no stored
 *   file to keep.
 */
export function createPayload(values: Record<string, unknown>): Record<string, unknown> {
  const payload: Record<string, unknown> = {}

  for (const [key, val] of Object.entries(values)) {
    if (val !== null && typeof val === 'object' && !(val instanceof File) && !Array.isArray(val)) {
      const record = val as Record<string, unknown>

      if ('id' in record && 'title' in record && !('resourceType' in record)) {
        payload[key] = record.id
        continue
      }
    }

    payload[key] = val
  }

  return payload
}
