/**
 * Timezone-stable handling for date-only (`YYYY-MM-DD`) values.
 *
 * A native date field is a CALENDAR date, not an instant. Round-tripping it
 * through UTC (`new Date('2026-07-20')` parses as UTC midnight;
 * `d.toISOString()` serialises as UTC) shifts the day by one in any timezone
 * that isn't UTC — back a day east of UTC on write, back a day west of UTC on
 * read. These helpers keep the value in the LOCAL calendar so the picked day
 * is the stored day everywhere.
 */

const pad = (n: number): string => String(n).padStart(2, '0')

/** Format a Date as a local `YYYY-MM-DD` calendar date, with no UTC round-trip. */
export function formatLocalDate(d: Date): string {
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`
}

/**
 * Parse a date-only value into a LOCAL midnight `Date`. A `YYYY-MM-DD` string is
 * read as a local calendar date — `new Date(s)` would parse it as UTC midnight
 * and shift the day west of UTC. Other string inputs fall back to the native
 * parser (so a full datetime still resolves). Empty/invalid input → `null`.
 */
export function parseLocalDate(value: unknown): Date | null {
  if (typeof value !== 'string' || value === '') {
    return null
  }

  const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value)
  if (match) {
    const year = Number(match[1])
    const month = Number(match[2])
    const day = Number(match[3])
    const d = new Date(year, month - 1, day)
    // Reject out-of-range values (JS silently rolls `2026-13-40` over to a
    // valid Date) and the legacy 0-99 year offset (`new Date(99, …)` → 1999):
    // the constructed date must round-trip to the exact input components.
    if (d.getFullYear() === year && d.getMonth() === month - 1 && d.getDate() === day) {
      return d
    }
    return null
  }

  const d = new Date(value)
  return isNaN(d.getTime()) ? null : d
}
