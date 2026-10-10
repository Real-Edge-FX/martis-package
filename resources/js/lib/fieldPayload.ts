import { flattenFields } from '@/lib/flattenFields'

/** Field types whose loaded value is a stored file (`{ url, ... }`). */
const FILE_FIELD_TYPES = new Set(['file', 'image', 'avatar', 'audio'])

/**
 * Reduces a form's values to what the server takes, deciding per attribute
 * by the field's type (v1.39.7), never by the shape of the value: a KeyValue
 * map, a JSON object or a custom field's value can carry `id`, `title` or
 * `url` keys and must reach the server as the user left it.
 *
 * - `belongs_to`: a `{ id, ... }` map is reduced to its id.
 * - `morph_to`: keeps its target map, the id alone does not say which model.
 * - A file type: a stored `{ url, ... }` value is left out on update (the
 *   server keeps the file) and kept on create (a copy made from a replicated
 *   record carries its files).
 * - Every other type, and any attribute without a definition: untouched.
 */
export function fieldPayload(
  values: Record<string, unknown>,
  fields: readonly unknown[],
  context: 'create' | 'update',
): Record<string, unknown> {
  const types = new Map<string, string>()
  for (const field of flattenFields(fields)) types.set(field.attribute, field.type)

  const payload: Record<string, unknown> = {}

  for (const [key, val] of Object.entries(values)) {
    const type = types.get(key)

    if (val !== null && typeof val === 'object' && !Array.isArray(val) && !(val instanceof File)) {
      const record = val as Record<string, unknown>

      if (type === 'belongs_to' && 'id' in record) {
        payload[key] = record.id
        continue
      }

      if (context === 'update' && type !== undefined && FILE_FIELD_TYPES.has(type) && 'url' in record) {
        continue
      }
    }

    payload[key] = val
  }

  return payload
}
