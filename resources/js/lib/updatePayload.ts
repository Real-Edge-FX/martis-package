import type { FieldDefinition } from '@/types'
import { fieldTypesByAttribute, isFileFieldType, reduceBelongsTo } from '@/lib/formPayload'

/**
 * The values an update form (the update page and the update drawer)
 * submits for a record. `fields` is the form's field definitions (containers
 * included): a value is shaped by its field's type, never by its own shape,
 * so a KeyValue or a JSON field whose map has `id`, `title` or `url` keys is
 * sent as it is.
 *
 * - A file value that is still the stored one (the `{ url, ... }` object
 *   the record loaded with) is left out, so the server keeps the file.
 * - A BelongsTo value (`{ id, title }`) is reduced to its id. A soft-deleted
 *   target (`trashed: true`) also sends `<attribute>_trashed: true`, Nova's
 *   opt-in, so the Relatable rule keeps accepting it on a save of any field.
 * - A MorphTo value keeps its target map (`{ type, id, title, resourceType }`
 *   as loaded, `{ resourceType, id, title }` once a record is picked): the
 *   id alone does not say which model it points to, and the server ignores
 *   anything that is not a map, so a changed target would never be saved.
 * - Any other value, and a value whose attribute has no definition, is untouched.
 */
export function updatePayload(
  values: Record<string, unknown>,
  fields: readonly FieldDefinition[],
): Record<string, unknown> {
  const types = fieldTypesByAttribute(fields)
  const payload: Record<string, unknown> = {}

  for (const [key, val] of Object.entries(values)) {
    const type = types.get(key)

    if (type === 'belongs_to') {
      reduceBelongsTo(payload, key, val)
      continue
    }

    if (
      isFileFieldType(type) &&
      val !== null &&
      typeof val === 'object' &&
      !(val instanceof File) &&
      'url' in (val as Record<string, unknown>)
    ) {
      continue
    }

    payload[key] = val
  }

  return payload
}
