const hasOwn = Object.prototype.hasOwnProperty

/**
 * The entry stored under `key` in a lookup table, or `undefined` when the
 * table has no entry of its own under that key.
 *
 * A table is a plain object, and `table[key]` / `key in table` also find what
 * every object inherits: `constructor` is `Object`, `__proto__` is
 * `Object.prototype`. Where the key is record data (a stored icon name, a
 * badge value, a multi-select value), a value spelled like an inherited member
 * then resolves to that member instead of falling through, and rendering it
 * throws ("Objects are not valid as a React child") for everyone who opens the
 * record.
 */
export function ownEntry<T>(table: Readonly<Record<string, T>> | null | undefined, key: string): T | undefined {
  return table !== null && table !== undefined && hasOwn.call(table, key) ? table[key] : undefined
}

/** Whether the table has an entry of its own under `key` (see {@link ownEntry}). */
export function hasOwnEntry(table: Readonly<Record<string, unknown>> | null | undefined, key: string): boolean {
  return table !== null && table !== undefined && hasOwn.call(table, key)
}
