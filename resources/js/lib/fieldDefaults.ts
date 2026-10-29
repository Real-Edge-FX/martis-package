import { flattenFields } from '@/lib/flattenFields'

/**
 * The values a new form starts from: each field's `default()`, as the server
 * serialises it (`defaultValue`, a closure already resolved for the request).
 * A field without a default is left out, so its input starts empty. Fields
 * inside layout containers (`panel` / `section` / `tab_group`) are included.
 */
export function fieldDefaults(fields: readonly unknown[]): Record<string, unknown> {
  const values: Record<string, unknown> = {}
  for (const field of flattenFields(fields)) {
    const value = field.defaultValue
    if (value !== null && value !== undefined) values[field.attribute] = value
  }
  return values
}
