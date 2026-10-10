import { flattenFields } from '@/lib/flattenFields'

/** The field types that hold an uploaded file (`{ url, ... }` once stored). */
const FILE_FIELD_TYPES: ReadonlySet<string> = new Set(['file', 'image', 'avatar', 'audio'])

/** The type of each field of a (possibly container-nested) field tree, by attribute. */
export function fieldTypesByAttribute(fields: readonly unknown[]): Map<string, string> {
  const types = new Map<string, string>()
  for (const field of flattenFields(fields)) {
    types.set(field.attribute, field.type)
  }
  return types
}

export function isFileFieldType(type: string | undefined): boolean {
  return type !== undefined && FILE_FIELD_TYPES.has(type)
}

/**
 * Write a BelongsTo value the way the server reads it: its id and, when the
 * target is soft-deleted (`trashed: true` in the value the form holds), Nova's
 * `<attribute>_trashed` opt-in, which the Relatable rule needs to accept it.
 * A scalar (an id already) or a null goes through unchanged.
 */
export function reduceBelongsTo(payload: Record<string, unknown>, key: string, val: unknown): void {
  if (val !== null && typeof val === 'object' && !Array.isArray(val) && !(val instanceof File) && 'id' in val) {
    const record = val as Record<string, unknown>
    payload[key] = record.id
    if (record.trashed === true) {
      payload[`${key}_trashed`] = true
    }
    return
  }
  payload[key] = val
}
