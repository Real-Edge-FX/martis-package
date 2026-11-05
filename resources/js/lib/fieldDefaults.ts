import { flattenFields } from '@/lib/flattenFields'

/**
 * The values a new form starts from: each field's `default()`, as the server
 * serialises it (`defaultValue`, a closure already resolved for the request).
 * A field without a default is left out, so its input starts empty. Fields
 * inside layout containers (`panel` / `section` / `tab_group`) are included.
 */
export function fieldDefaults(fields: readonly unknown[], options: { dropUnpickableSelect?: boolean } = {}): Record<string, unknown> {
  const values: Record<string, unknown> = {}
  for (const field of flattenFields(fields)) {
    const value = field.defaultValue
    if (value === null || value === undefined) continue
    if (options.dropUnpickableSelect && !isPickableSelectValue(field as unknown as Record<string, unknown>, value)) continue
    values[field.attribute] = value
  }
  return values
}

/**
 * A value a link prefilled for a `select` must be one of its options, so a
 * crafted link cannot start the form from a choice the user cannot make. A
 * select that takes custom values or searches on the server is left alone.
 */
function isPickableSelectValue(field: Record<string, unknown>, value: unknown): boolean {
  if (field.type !== 'select' || field.allowCustomValues || field.remoteOptionsSearch) return true
  const options = Array.isArray(field.options) ? (field.options as { value?: unknown }[]) : []
  return options.some((option) => String(option?.value) === String(value))
}
