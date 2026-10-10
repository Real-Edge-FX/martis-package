import { fieldPayload } from '@/lib/fieldPayload'

/**
 * The values a create form (the create page, the create drawer and the
 * inline create modal) submits for a new record (v1.39.6, field-aware since
 * v1.39.7). See `fieldPayload()`: a BelongsTo `{ id, title }` (such as the
 * pre-filled parent of a create-via-relation form) is reduced to its id, a
 * MorphTo keeps its map, a `{ url, ... }` file value is kept, and the value
 * of any other field type is untouched.
 */
export function createPayload(values: Record<string, unknown>, fields: readonly unknown[]): Record<string, unknown> {
  return fieldPayload(values, fields, 'create')
}
