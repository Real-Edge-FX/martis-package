/**
 * The values an update form (the update page and the update drawer)
 * submits for a record.
 *
 * - A file value that is still the stored one (the `{ url, ... }` object
 *   the record loaded with) is left out, so the server keeps the file.
 * - A BelongsTo value (`{ id, title }`) is reduced to its id.
 * - A MorphTo value keeps its target map (`{ type, id, title, resourceType }`
 *   as loaded, `{ resourceType, id, title }` once a record is picked): the
 *   id alone does not say which model it points to, and the server ignores
 *   anything that is not a map, so a changed target would never be saved.
 */
export function updatePayload(values: Record<string, unknown>): Record<string, unknown> {
  const payload: Record<string, unknown> = {}

  for (const [key, val] of Object.entries(values)) {
    if (val !== null && typeof val === 'object' && !(val instanceof File)) {
      const record = val as Record<string, unknown>

      if ('url' in record) {
        continue
      }

      if ('id' in record && 'title' in record && !('resourceType' in record)) {
        payload[key] = record.id
        continue
      }
    }

    payload[key] = val
  }

  return payload
}
