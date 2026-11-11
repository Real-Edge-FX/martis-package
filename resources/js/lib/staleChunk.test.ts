import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { STALE_CHUNK_RELOAD_KEY, isChunkLoadError, reloadOnStaleChunk } from '@/lib/staleChunk'

/*
 * `reloadOnStaleChunk` is the `@martis/runtime` helper for the rejection of a
 * dynamic import: a chunk a deploy replaced reloads the tab once, then the
 * error is rethrown. The registry's lazy wrapper uses it the same way.
 */

const reload = vi.fn()

beforeEach(() => {
  reload.mockReset()
  window.sessionStorage.removeItem(STALE_CHUNK_RELOAD_KEY)
  vi.stubGlobal('location', { ...window.location, reload })
  window.MartisConfig = {} as never
})

afterEach(() => {
  vi.unstubAllGlobals()
  delete window.MartisConfig
})

describe('isChunkLoadError', () => {
  it.each([
    new TypeError('Failed to fetch dynamically imported module: https://x.test/chunks/A-1.js'),
    new TypeError('Importing a module script failed.'),
    new Error('error loading dynamically imported module: https://x.test/a.js'),
    Object.assign(new Error('Loading chunk 12 failed.'), { name: 'ChunkLoadError' }),
    new Error('Loading CSS chunk 7 failed.'),
  ])('recognises %s', (error) => {
    expect(isChunkLoadError(error)).toBe(true)
  })

  it.each([new Error('boom'), new TypeError('x is undefined'), null, undefined, 'Failed to fetch dynamically imported module'])('ignores %s', (error) => {
    expect(isChunkLoadError(error)).toBe(false)
  })
})

describe('reloadOnStaleChunk', () => {
  const chunkError = () => new TypeError('Failed to fetch dynamically imported module: /a.js')

  it('reloads once per tab, then rethrows', () => {
    const first = reloadOnStaleChunk(chunkError())

    expect(reload).toHaveBeenCalledTimes(1)
    expect(first).toBeInstanceOf(Promise)
    expect(Number(window.sessionStorage.getItem(STALE_CHUNK_RELOAD_KEY))).toBeGreaterThan(0)

    const error = chunkError()
    expect(() => reloadOnStaleChunk(error)).toThrow(error)
    expect(reload).toHaveBeenCalledTimes(1)
  })

  it('reloads again once the guard window has passed', () => {
    vi.useFakeTimers()
    vi.setSystemTime(new Date('2026-01-01T00:00:00Z'))
    reloadOnStaleChunk(chunkError())
    expect(reload).toHaveBeenCalledTimes(1)

    vi.setSystemTime(new Date('2026-01-01T00:00:30Z'))
    expect(() => reloadOnStaleChunk(chunkError())).toThrow()
    expect(reload).toHaveBeenCalledTimes(1)

    vi.setSystemTime(new Date('2026-01-01T00:01:01Z'))
    reloadOnStaleChunk(chunkError())
    expect(reload).toHaveBeenCalledTimes(2)
    vi.useRealTimers()
  })

  it('treats an unreadable guard value as a recent reload', () => {
    window.sessionStorage.setItem(STALE_CHUNK_RELOAD_KEY, 'garbage')

    expect(() => reloadOnStaleChunk(chunkError())).toThrow()
    expect(reload).not.toHaveBeenCalled()
  })

  it('rethrows instead of reloading while the browser is offline', () => {
    vi.spyOn(window.navigator, 'onLine', 'get').mockReturnValue(false)
    const error = chunkError()

    expect(() => reloadOnStaleChunk(error)).toThrow(error)
    expect(reload).not.toHaveBeenCalled()
    expect(window.sessionStorage.getItem(STALE_CHUNK_RELOAD_KEY)).toBeNull()

    vi.restoreAllMocks()
  })

  it('rethrows an error that is not a chunk error without reloading', () => {
    const error = new Error('boom')

    expect(() => reloadOnStaleChunk(error)).toThrow(error)
    expect(reload).not.toHaveBeenCalled()
  })

  it('never reloads when the kill switch is off', () => {
    window.MartisConfig = { extensionsReloadOnStale: false } as never
    const error = chunkError()

    expect(() => reloadOnStaleChunk(error)).toThrow(error)
    expect(reload).not.toHaveBeenCalled()
  })

  it('rethrows instead of looping when storage is blocked', () => {
    vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
      throw new Error('denied')
    })
    const error = chunkError()

    expect(() => reloadOnStaleChunk(error)).toThrow(error)
    expect(reload).not.toHaveBeenCalled()

    vi.restoreAllMocks()
  })

  it('works as a promise rejection handler', async () => {
    const error = new Error('boom')

    await expect(Promise.reject(error).catch(reloadOnStaleChunk)).rejects.toBe(error)
  })
})
