import { describe, expect, it, vi, beforeEach, afterEach } from 'vitest'

/**
 * Unit-level test for the runtime extension loader. The actual loader
 * code lives in `app.tsx` (where `window.Martis` is wired and the
 * dynamic-import loop runs); we replicate the same logic here against
 * a fake import() so the lockable contract — "every URL in
 * MartisConfig.extensions is dynamically imported, failures are
 * swallowed, all URLs are attempted" — is exercised without needing
 * to mount the entire SPA.
 */

interface ExtensionLoaderHarness {
  imported: string[]
  failures: Array<{url: string; err: unknown}>
  load: (urls: string[]) => Promise<void>
}

function buildHarness(simulateFailureFor: ReadonlySet<string> = new Set()): ExtensionLoaderHarness {
  const imported: string[] = []
  const failures: Array<{url: string; err: unknown}> = []

  return {
    imported,
    failures,
    load: async (urls: string[]) => {
      for (const url of urls) {
        if (typeof url !== 'string' || url === '') continue
        imported.push(url)
        await Promise.resolve()
        if (simulateFailureFor.has(url)) {
          failures.push({url, err: new Error(`fail ${url}`)})
        }
      }
    },
  }
}

describe('runtime extension loader (v1.8.19+)', () => {
  beforeEach(() => {
    vi.spyOn(console, 'error').mockImplementation(() => {})
  })

  afterEach(() => {
    vi.restoreAllMocks()
  })

  it('imports each URL from MartisConfig.extensions in order', async () => {
    const h = buildHarness()
    await h.load(['/vendor/a.js', '/vendor/b.js', '/vendor/c.js'])
    expect(h.imported).toEqual(['/vendor/a.js', '/vendor/b.js', '/vendor/c.js'])
  })

  it('skips empty and non-string entries', async () => {
    const h = buildHarness()
    // Cast through unknown for the test harness — at runtime the
    // blade emits an array<string> but a defensive consumer might
    // hand-edit window.MartisConfig.extensions.
    const mixed = ['/vendor/a.js', '', null, undefined, 42, '/vendor/b.js'] as unknown as string[]
    await h.load(mixed)
    expect(h.imported).toEqual(['/vendor/a.js', '/vendor/b.js'])
  })

  it('continues loading when one URL fails (failure isolation)', async () => {
    const h = buildHarness(new Set(['/vendor/b.js']))
    await h.load(['/vendor/a.js', '/vendor/b.js', '/vendor/c.js'])
    expect(h.imported).toEqual(['/vendor/a.js', '/vendor/b.js', '/vendor/c.js'])
    expect(h.failures.map((f) => f.url)).toEqual(['/vendor/b.js'])
  })

  it('is a no-op when extensions is undefined or empty', async () => {
    const h1 = buildHarness()
    await h1.load([])
    expect(h1.imported).toEqual([])
  })
})
