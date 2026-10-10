/**
 * The values a create form (the create page, the create drawer and the
 * inline create modal) submits for a new record (v1.39.6).
 *
 * - A BelongsTo value (`{ id, title }`, the shape a pre-filled or read-only
 *   picker holds, such as the parent in a create-via-relation form) is
 *   reduced to its id, as `updatePayload()` does on update, so the
 *   consumer's rules (`Rule::exists()`) and the fill receive the key.
 * - A MorphTo value (`{ resourceType, id, title }`) keeps its target map:
 *   the id alone does not say which model it points to.
 * - Unlike `updatePayload()`, a `{ url, ... }` file value is kept: a copy
 *   made from a replicated record carries its files, nothing is stored yet
 *   for the server to keep.
 */
export function createPayload(values: Record<string, unknown>): Record<string, unknown> {
  const payload: Record<string, unknown> = {}

  for (const [key, val] of Object.entries(values)) {
    if (val !== null && typeof val === 'object' && !(val instanceof File)) {
      const record = val as Record<string, unknown>

      if ('id' in record && 'title' in record && !('resourceType' in record) && !('url' in record)) {
        payload[key] = record.id
        continue
      }
    }

    payload[key] = val
  }

  return payload
}
