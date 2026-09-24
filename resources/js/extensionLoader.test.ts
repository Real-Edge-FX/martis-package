import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { EXTENSION_LOAD_TIMEOUT_MS, loadConsumerExtensions } from '@/lib/extensionLoader'

/**
 * The runtime extension loader `app.tsx` awaits before mounting React,
 * exercised directly with a fake importer (the browser's `import()`).
 */

describe('runtime extension loader (v1.8.19+)', () => {
  let warn: ReturnType<typeof vi.spyOn>
  let error: ReturnType<typeof vi.spyOn>

  beforeEach(() => {
    warn = vi.spyOn(console, 'warn').mockImplementation(() => {})
    error = vi.spyOn(console, 'error').mockImplementation(() => {})
  })

  afterEach(() => {
    vi.useRealTimers()
    vi.restoreAllMocks()
    delete (window as { MartisConfig?: unknown }).MartisConfig
  })

  it('imports every URL', async () => {
    const importer = vi.fn((_url: string) => Promise.resolve({}))

    await loadConsumerExtensions(['/vendor/a.js', '/vendor/b.js', '/vendor/c.js'], importer)

    expect(importer.mock.calls.map(([url]) => url)).toEqual(['/vendor/a.js', '/vendor/b.js', '/vendor/c.js'])
  })

  it('skips empty and non-string entries', async () => {
    const importer = vi.fn((_url: string) => Promise.resolve({}))

    await loadConsumerExtensions(['/vendor/a.js', '', null, undefined, 42, '/vendor/b.js'], importer)

    expect(importer.mock.calls.map(([url]) => url)).toEqual(['/vendor/a.js', '/vendor/b.js'])
  })

  it('keeps loading the other bundles when one fails', async () => {
    const importer = vi.fn((url: string) => (url === '/vendor/b.js' ? Promise.reject(new Error('boom')) : Promise.resolve({})))

    await loadConsumerExtensions(['/vendor/a.js', '/vendor/b.js', '/vendor/c.js'], importer)

    expect(importer).toHaveBeenCalledTimes(3)
    expect(error).toHaveBeenCalledTimes(1)
    expect(error.mock.calls[0]?.[1]).toBe('/vendor/b.js')
  })

  it('reads the URLs from window.MartisConfig.extensions by default and is a no-op without them', async () => {
    const importer = vi.fn((_url: string) => Promise.resolve({}))

    await loadConsumerExtensions(undefined, importer)
    expect(importer).not.toHaveBeenCalled()

    ;(window as { MartisConfig?: unknown }).MartisConfig = { extensions: ['/vendor/martis-user/extensions.js'] }
    await loadConsumerExtensions(undefined, importer)
    expect(importer).toHaveBeenCalledWith('/vendor/martis-user/extensions.js')
  })

  it('gives up on a bundle that does not load in time and warns once', async () => {
    vi.useFakeTimers()
    const importer = vi.fn((_url: string) => new Promise<unknown>(() => {}))

    const loading = loadConsumerExtensions(['/vendor/slow.js'], importer)
    await vi.advanceTimersByTimeAsync(EXTENSION_LOAD_TIMEOUT_MS)
    await loading

    expect(warn).toHaveBeenCalledTimes(1)
    expect(warn.mock.calls[0]?.[3]).toBe('/vendor/slow.js')
  })

  it('does not warn about a timeout for a bundle that loaded in time', async () => {
    vi.useFakeTimers()
    const importer = vi.fn((_url: string) => Promise.resolve({}))

    await loadConsumerExtensions(['/vendor/fast.js'], importer)
    await vi.advanceTimersByTimeAsync(EXTENSION_LOAD_TIMEOUT_MS * 2)

    expect(warn).not.toHaveBeenCalled()
  })
})
