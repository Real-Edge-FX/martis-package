/**
 * A filter value counts as cleared (no active filter) when it is null,
 * undefined, an empty string, or an empty array. PrimeReact's MultiSelect
 * emits `[]` on clear / deselect-all, so multi-select filters must treat the
 * empty array as cleared. Otherwise a stale chip, an inflated badge, and a
 * wasted round-trip survive a full deselect. Single-select Dropdown emits
 * null, so it was already covered by the scalar checks.
 */
export function isBlankFilterValue(value: unknown): boolean {
  return value === null || value === undefined || value === '' || (Array.isArray(value) && value.length === 0)
}
