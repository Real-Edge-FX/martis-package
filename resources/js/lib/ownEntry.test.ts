import { describe, expect, it } from 'vitest'
import { hasOwnEntry, ownEntry } from './ownEntry'

describe('ownEntry', () => {
  const table = { success: 'green', constructor: 'mine' } as Record<string, string>

  it('returns the entry the table holds under the key', () => {
    expect(ownEntry(table, 'success')).toBe('green')
    expect(hasOwnEntry(table, 'success')).toBe(true)
  })

  it('returns undefined for a key the table lacks', () => {
    expect(ownEntry(table, 'danger')).toBeUndefined()
    expect(hasOwnEntry(table, 'danger')).toBe(false)
  })

  it.each(['__proto__', 'toString', 'valueOf', 'hasOwnProperty', 'isPrototypeOf'])('does not find the inherited member %s', (key) => {
    expect(ownEntry({}, key)).toBeUndefined()
    expect(hasOwnEntry({}, key)).toBe(false)
  })

  it('does find an own entry that happens to be spelled like an inherited member', () => {
    expect(ownEntry(table, 'constructor')).toBe('mine')
    expect(hasOwnEntry(table, 'constructor')).toBe(true)
  })

  it('treats a missing table as empty', () => {
    expect(ownEntry(null, 'a')).toBeUndefined()
    expect(ownEntry(undefined, 'a')).toBeUndefined()
    expect(hasOwnEntry(undefined, 'a')).toBe(false)
  })
})
