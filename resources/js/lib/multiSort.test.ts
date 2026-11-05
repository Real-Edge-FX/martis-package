import { describe, it, expect } from 'vitest'
import {
  additiveSort, clickSort, moveSortKey, normalizeSort, parseSortParam, readSavedSort, sameSort, serializeSort,
  type SortKey,
} from '@/lib/multiSort'

const asc = (attribute: string): SortKey => ({ attribute, direction: 'asc' })
const desc = (attribute: string): SortKey => ({ attribute, direction: 'desc' })

describe('multiSort wire format', () => {
  it('serializes and parses a list', () => {
    const keys = [asc('category'), desc('position')]

    expect(serializeSort(keys)).toBe('category:asc,position:desc')
    expect(parseSortParam('category:asc,position:desc')).toEqual(keys)
    expect(serializeSort([])).toBe('')
  })

  it('reads a lone key with the legacy direction parameter', () => {
    expect(parseSortParam('title', 'desc')).toEqual([desc('title')])
    expect(parseSortParam('title')).toEqual([asc('title')])
    expect(parseSortParam('title:asc', 'desc')).toEqual([asc('title')])
    expect(parseSortParam(null)).toEqual([])
  })

  it('caps at three keys, keeps the first of a duplicate and drops a bad suffix', () => {
    expect(parseSortParam('a,b:desc,a:desc,c,d')).toEqual([asc('a'), desc('b'), asc('c')])
    expect(parseSortParam('a:up,b')).toEqual([asc('b')])
  })
})

describe('multiSort saved state', () => {
  it('normalizes a list from storage', () => {
    expect(normalizeSort([{ attribute: 'a', direction: 'desc' }, { attribute: 'a', direction: 'asc' }, { attribute: '', direction: 'asc' }, 'x', null]))
      .toEqual([desc('a')])
    expect(normalizeSort([{ attribute: 'a', direction: 'sideways' }])).toEqual([asc('a')])
    expect(normalizeSort('nope')).toEqual([])
    expect(normalizeSort(Array.from({ length: 6 }, (_, i) => asc(`k${i}`)))).toHaveLength(3)
  })

  it('reads an entry of the earlier sortBy / sortDir shape as one key', () => {
    expect(readSavedSort({ sortBy: 'title', sortDir: 'desc' })).toEqual([desc('title')])
    expect(readSavedSort({ sortBy: 'title' })).toEqual([asc('title')])
    expect(readSavedSort({ sortBy: null, sortDir: 'asc' })).toEqual([])
    expect(readSavedSort({ sort: [desc('a'), asc('b')], sortBy: 'old' })).toEqual([desc('a'), asc('b')])
  })
})

describe('multiSort clicks', () => {
  it('click sorts by the column alone, and flips it when clicked again', () => {
    expect(clickSort([], 'a')).toEqual([asc('a')])
    expect(clickSort([asc('a')], 'a')).toEqual([desc('a')])
    expect(clickSort([desc('a')], 'a')).toEqual([asc('a')])
    expect(clickSort([asc('a'), asc('b')], 'b')).toEqual([asc('b')])
    expect(clickSort([asc('a')], 'b')).toEqual([asc('b')])
  })

  it('shift+click adds, then cycles asc, desc, removed', () => {
    let keys = additiveSort([asc('a')], 'b').keys
    expect(keys).toEqual([asc('a'), asc('b')])
    keys = additiveSort(keys, 'b').keys
    expect(keys).toEqual([asc('a'), desc('b')])
    keys = additiveSort(keys, 'b').keys
    expect(keys).toEqual([asc('a')])
  })

  it('refuses a fourth key and leaves the list as it was', () => {
    const three = [asc('a'), asc('b'), asc('c')]
    const result = additiveSort(three, 'd')

    expect(result.refused).toBe(true)
    expect(result.keys).toEqual(three)
    expect(additiveSort(three, 'c').refused).toBe(false)
  })

  it('moves a key up or down and ignores a move past the ends', () => {
    const keys = [asc('a'), asc('b'), asc('c')]

    expect(moveSortKey(keys, 1, -1).map((k) => k.attribute)).toEqual(['b', 'a', 'c'])
    expect(moveSortKey(keys, 1, 1).map((k) => k.attribute)).toEqual(['a', 'c', 'b'])
    expect(moveSortKey(keys, 0, -1)).toEqual(keys)
    expect(moveSortKey(keys, 2, 1)).toEqual(keys)
  })

  it('compares lists', () => {
    expect(sameSort([asc('a')], [asc('a')])).toBe(true)
    expect(sameSort([asc('a')], [desc('a')])).toBe(false)
    expect(sameSort([asc('a'), asc('b')], [asc('b'), asc('a')])).toBe(false)
  })
})
