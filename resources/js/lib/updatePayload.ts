import { fieldPayload } from '@/lib/fieldPayload'

/**
 * The values an update form (the update page and the update drawer)
 * submits for a record (field-aware since v1.39.7). See `fieldPayload()`:
 * a BelongsTo `{ id, title }` is reduced to its id, a MorphTo keeps its
 * target map, a file field's stored `{ url, ... }` value is left out so the
 * server keeps the file, and the value of any other field type is untouched.
 */
export function updatePayload(values: Record<string, unknown>, fields: readonly unknown[]): Record<string, unknown> {
  return fieldPayload(values, fields, 'update')
}
