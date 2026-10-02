import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

// F084: the saved index views (search text, applied filter values) belong to
// the signed-in user who wrote them. The key names that user, a reader names
// the user it reads for, and the entries of anyone else, or of the format
// before they named their user, are dropped when the session boots, and all of
// them on sign-out, from both web storages.

const stickyViews = vi.hoisted(() => ({ enabled: true, scope: 'session' as 'session' | 'local', persist: {} }))

vi.mock('./config', () => ({ config: { stickyViews } }))

import {
  clearAllStickyViews,
  clearStickyView,
  purgeForeignStickyViews,
  readStickyView,
  writeStickyView,
} from './useStickyView'

const STATE = { search: 'alice@example.com', activeFilters: { status: 'open' }, page: 3 }

function keys(storage: Storage): string[] {
  return Array.from({ length: storage.length }, (_, i) => storage.key(i)!).sort()
}

beforeEach(() => {
  stickyViews.enabled = true
  stickyViews.scope = 'session'
  sessionStorage.clear()
  localStorage.clear()
})

afterEach(() => {
  sessionStorage.clear()
  localStorage.clear()
})

describe('sticky views are written under the signed-in user', () => {
  it('stores the view under martis:view:{userId}:{uriKey}', () => {
    writeStickyView(7, 'posts', STATE)

    expect(keys(sessionStorage)).toEqual(['martis:view:7:posts'])
    expect(readStickyView(7, 'posts')).toEqual(STATE)
  })

  it('keeps a view away from every other user of the same browser', () => {
    writeStickyView(7, 'posts', STATE)

    expect(readStickyView(8, 'posts')).toBeNull()
    expect(readStickyView('7', 'posts')).toEqual(STATE)
  })

  it('keeps two users apart when their ids share a prefix or hold the separator', () => {
    writeStickyView('a', 'b:posts', { search: 'first' })
    writeStickyView('a:b', 'posts', { search: 'second' })

    expect(readStickyView('a', 'b:posts')).toEqual({ search: 'first' })
    expect(readStickyView('a:b', 'posts')).toEqual({ search: 'second' })
    expect(keys(sessionStorage)).toEqual(['martis:view:a%3Ab:posts', 'martis:view:a:b:posts'])
  })

  it('does not read the unscoped key of the earlier format', () => {
    sessionStorage.setItem('martis:view:posts', JSON.stringify(STATE))

    expect(readStickyView(7, 'posts')).toBeNull()
  })

  it.each([null, undefined, ''])('reads and writes nothing for no user (%j)', (owner) => {
    writeStickyView(owner, 'posts', STATE)

    expect(keys(sessionStorage)).toEqual([])
    expect(readStickyView(owner, 'posts')).toBeNull()
  })

  it('clears one resource view of one user only', () => {
    writeStickyView(7, 'posts', STATE)
    writeStickyView(7, 'users', STATE)
    writeStickyView(8, 'posts', STATE)

    clearStickyView(7, 'posts')

    expect(keys(sessionStorage)).toEqual(['martis:view:7:users', 'martis:view:8:posts'])
  })

  it('writes to localStorage when the scope says so', () => {
    stickyViews.scope = 'local'
    writeStickyView(7, 'posts', STATE)

    expect(keys(localStorage)).toEqual(['martis:view:7:posts'])
    expect(keys(sessionStorage)).toEqual([])
  })
})

describe('purgeForeignStickyViews', () => {
  it("drops another user's entries and the unscoped keys, from both storages, and keeps the user's own", () => {
    for (const storage of [sessionStorage, localStorage]) {
      storage.setItem('martis:view:7:posts', '{}')
      storage.setItem('martis:view:8:posts', '{}')
      storage.setItem('martis:view:70:posts', '{}')
      storage.setItem('martis:view:posts', '{}')
      storage.setItem('unrelated', 'kept')
    }

    purgeForeignStickyViews(7)

    expect(keys(sessionStorage)).toEqual(['martis:view:7:posts', 'unrelated'])
    expect(keys(localStorage)).toEqual(['martis:view:7:posts', 'unrelated'])
  })

  it('does nothing when no user is named', () => {
    sessionStorage.setItem('martis:view:7:posts', '{}')

    purgeForeignStickyViews(null)

    expect(keys(sessionStorage)).toEqual(['martis:view:7:posts'])
  })
})

describe('clearAllStickyViews', () => {
  it('drops every entry of every user from sessionStorage and localStorage, whatever the scope', () => {
    for (const storage of [sessionStorage, localStorage]) {
      storage.setItem('martis:view:7:posts', '{}')
      storage.setItem('martis:view:8:users', '{}')
      storage.setItem('martis:view:posts', '{}')
      storage.setItem('martis-preferences', '{}')
    }

    clearAllStickyViews()

    expect(keys(sessionStorage)).toEqual(['martis-preferences'])
    expect(keys(localStorage)).toEqual(['martis-preferences'])
  })

  it('still clears the other storage when one is blocked', () => {
    localStorage.setItem('martis:view:7:posts', '{}')
    const blocked = vi.spyOn(window, 'sessionStorage', 'get').mockImplementation(() => {
      throw new DOMException('blocked', 'SecurityError')
    })

    expect(() => clearAllStickyViews()).not.toThrow()
    blocked.mockRestore()

    expect(keys(localStorage)).toEqual([])
  })
})
