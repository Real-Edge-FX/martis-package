/**
 * Multi-column sort state shared by the index, the lens and the
 * relationship panels. The wire format is `?sort=a:asc,b:desc` (see
 * `Martis\Support\ListSort`): at most {@link MAX_SORT_KEYS} keys, the
 * first one the most significant.
 */

export type SortDirection = 'asc' | 'desc'

export interface SortKey {
  attribute: string
  direction: SortDirection
}

/** Keys past this many are ignored by the server and refused by the UI. */
export const MAX_SORT_KEYS = 3

const isDirection = (value: unknown): value is SortDirection => value === 'asc' || value === 'desc'

/**
 * Clean a list of keys from an untrusted source (a saved view, the schema):
 * well-formed keys only, a repeated attribute keeps its first occurrence,
 * and no more than the cap.
 */
export function normalizeSort(value: unknown): SortKey[] {
  if (!Array.isArray(value)) return []
  const keys: SortKey[] = []
  for (const item of value) {
    if (typeof item !== 'object' || item === null) continue
    const { attribute, direction } = item as { attribute?: unknown; direction?: unknown }
    if (typeof attribute !== 'string' || attribute === '') continue
    if (keys.some((key) => key.attribute === attribute)) continue
    keys.push({ attribute, direction: isDirection(direction) ? direction : 'asc' })
    if (keys.length === MAX_SORT_KEYS) break
  }
  return keys
}

/**
 * The sort a saved view holds: its `sort` list, or, for an entry written
 * before the list existed, its `sortBy` / `sortDir` pair read as one key.
 */
export function readSavedSort(saved: Record<string, unknown>): SortKey[] {
  if ('sort' in saved) return normalizeSort(saved.sort)
  if (typeof saved.sortBy === 'string' && saved.sortBy !== '') {
    return [{ attribute: saved.sortBy, direction: saved.sortDir === 'desc' ? 'desc' : 'asc' }]
  }
  return []
}

/** `a:asc,b:desc`, the value of `?sort=`. Empty for no keys. */
export function serializeSort(keys: readonly SortKey[]): string {
  return keys.map((key) => `${key.attribute}:${key.direction}`).join(',')
}

/**
 * Read a `?sort=` value (and the legacy `?direction=` of a lone key) back
 * into keys. The inverse of {@link serializeSort}.
 */
export function parseSortParam(sort: string | null | undefined, direction?: string | null): SortKey[] {
  if (!sort) return []
  const pieces = sort.split(',').map((piece) => piece.trim()).filter(Boolean)
  const keys: SortKey[] = []
  for (const piece of pieces) {
    let attribute = piece
    let keyDirection: SortDirection = pieces.length === 1 && direction?.toLowerCase() === 'desc' ? 'desc' : 'asc'
    const colon = piece.lastIndexOf(':')
    if (colon !== -1) {
      const suffix = piece.slice(colon + 1).toLowerCase()
      if (!isDirection(suffix)) continue
      attribute = piece.slice(0, colon).trim()
      keyDirection = suffix
    }
    if (attribute === '' || keys.some((key) => key.attribute === attribute)) continue
    keys.push({ attribute, direction: keyDirection })
    if (keys.length === MAX_SORT_KEYS) break
  }
  return keys
}

export function sameSort(a: readonly SortKey[], b: readonly SortKey[]): boolean {
  return a.length === b.length && a.every((key, i) => key.attribute === b[i].attribute && key.direction === b[i].direction)
}

const flip = (direction: SortDirection): SortDirection => (direction === 'asc' ? 'desc' : 'asc')

/** A plain click on a header: that column alone; clicked again, its direction flips. */
export function clickSort(keys: readonly SortKey[], attribute: string): SortKey[] {
  if (keys.length === 1 && keys[0].attribute === attribute) {
    return [{ attribute, direction: flip(keys[0].direction) }]
  }
  return [{ attribute, direction: 'asc' }]
}

/**
 * Shift+click on a header: add the column as the next key; on a column
 * already in the list, cycle asc, desc, removed. A key past the cap is
 * refused (`refused: true`, the list unchanged).
 */
export function additiveSort(keys: readonly SortKey[], attribute: string): { keys: SortKey[]; refused: boolean } {
  const index = keys.findIndex((key) => key.attribute === attribute)
  if (index === -1) {
    if (keys.length >= MAX_SORT_KEYS) return { keys: [...keys], refused: true }
    return { keys: [...keys, { attribute, direction: 'asc' }], refused: false }
  }
  if (keys[index].direction === 'asc') {
    return { keys: keys.map((key, i) => (i === index ? { attribute, direction: 'desc' as const } : key)), refused: false }
  }
  return { keys: keys.filter((_, i) => i !== index), refused: false }
}

/** Move the key at `index` by `offset` places (-1 up, +1 down); out of range is a no-op. */
export function moveSortKey(keys: readonly SortKey[], index: number, offset: -1 | 1): SortKey[] {
  const target = index + offset
  if (target < 0 || target >= keys.length) return [...keys]
  const next = [...keys]
  ;[next[index], next[target]] = [next[target], next[index]]
  return next
}
