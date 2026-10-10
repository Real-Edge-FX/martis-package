// @vitest-environment node
import { afterAll, beforeAll, describe, expect, it } from 'vitest'
import { build } from 'vite'
import { cpSync, mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import path from 'node:path'
import { pathToFileURL } from 'node:url'
import * as ReactDOM from 'react-dom'
import * as ReactDOMClient from 'react-dom/client'
import { reactDomClientHandle, reactDomHandle } from '@/lib/reactDomHandles'

/*
 * The `react-dom` and `react-dom/client` shims `martis:install` publishes
 * (v2.10.0). `@dnd-kit/core` imports `unstable_batchedUpdates` from
 * `react-dom`, which the shim did not export, so `npm run build:extensions`
 * failed with `"unstable_batchedUpdates" is not exported by
 * ".shims/react-dom.mjs"`, and `react-dom/client` had no alias at all.
 *
 * These tests build a scratch extension with the published Vite alias table
 * and shims, the way a consumer does, then run the bundle against a
 * `window.Martis` filled as the host's app.tsx fills it.
 */

const root = path.resolve(__dirname, '../..')
const stubs = path.join(root, 'stubs/extensions')

let app: string
const outputs = new Map<string, string>()

/** The alias table of the published Vite config, as Vite takes it. */
function aliases(shimsDir: string): { find: RegExp | string; replacement: string }[] {
  const config = readFileSync(path.join(stubs, 'vite.extensions.config.ts.stub'), 'utf8')
  const shimFiles = Object.fromEntries([...config.matchAll(/const (\w+) = path\.join\(shimsDir, '([\w-]+\.mjs)'\)/g)].map(([, variable, file]) => [variable, path.join(shimsDir, file)]))

  return [...config.matchAll(/\{find: (?:'([^']+)'|\/(.+?)\/([a-z]*)), replacement: (\w+)\}/g)]
    .filter(([, , , , replacement]) => replacement in shimFiles)
    .map(([, literal, source, flags, replacement]) => ({ find: literal ?? new RegExp(source, flags), replacement: shimFiles[replacement] }))
}

async function bundle(name: string, entry: string, files: Record<string, string>): Promise<string> {
  const dir = path.join(app, name)
  mkdirSync(path.join(dir, '.shims'), { recursive: true })
  for (const file of ['react', 'react-jsx-runtime', 'react-dom', 'react-dom-client', 'react-router-dom', 'react-i18next', 'tanstack-react-query', 'runtime']) {
    cpSync(path.join(stubs, `${file}-shim.mjs.stub`), path.join(dir, '.shims', `${file}.mjs`))
  }
  for (const [file, source] of Object.entries(files)) {
    mkdirSync(path.dirname(path.join(dir, file)), { recursive: true })
    writeFileSync(path.join(dir, file), source)
  }

  const result = await build({
    root: dir,
    configFile: false,
    logLevel: 'silent',
    resolve: { alias: aliases(path.join(dir, '.shims')) },
    build: { write: false, minify: false, lib: { entry: path.join(dir, entry), formats: ['es'], fileName: () => 'extensions.js' } },
  })
  const output = (Array.isArray(result) ? result[0] : result) as { output: { code: string }[] }

  return output.output[0].code
}

beforeAll(() => {
  app = mkdtempSync(path.join(tmpdir(), 'martis-react-dom-shims-'))
})

afterAll(() => {
  rmSync(app, { recursive: true, force: true })
})

// A third-party library as a consumer installs it: it imports the public
// ReactDOM 18 API from the bare specifiers, as `@dnd-kit/core` does.
const LIBRARY = `
import { unstable_batchedUpdates, createPortal, flushSync, version } from 'react-dom'
import ReactDOMDefault from 'react-dom'
import { createRoot, hydrateRoot } from 'react-dom/client'
import ReactDOMClientDefault from 'react-dom/client'

export const surface = { unstable_batchedUpdates, createPortal, flushSync, version, createRoot, hydrateRoot, ReactDOMDefault, ReactDOMClientDefault }
`

describe('the react-dom shims, built the way a consumer builds them', () => {
  it('builds an extension that imports unstable_batchedUpdates and version from react-dom and createRoot and hydrateRoot from react-dom/client', async () => {
    const code = await bundle('library', 'index.ts', { 'index.ts': LIBRARY })
    outputs.set('library', code)

    // Neither specifier is left for the browser to resolve, and the shims'
    // guards are in the bundle.
    expect(code).not.toMatch(/from\s*['"]react-dom(\/client)?['"]/)
    expect(code).toContain('window.Martis.reactDom')
    expect(code).toContain('window.Martis.reactDomClient')
  })

  it('resolves each name to the host\'s own when the bundle runs against window.Martis', async () => {
    const code = outputs.get('library') ?? await bundle('library', 'index.ts', { 'index.ts': LIBRARY })
    const file = path.join(app, 'library', 'extensions.mjs')
    writeFileSync(file, code)

    const host = globalThis as unknown as { window?: unknown }
    const previous = host.window
    host.window = { Martis: { reactDom: reactDomHandle, reactDomClient: reactDomClientHandle } }
    try {
      const { surface } = await import(/* @vite-ignore */ pathToFileURL(file).href) as { surface: Record<string, unknown> }

      expect(surface.unstable_batchedUpdates).toBe(reactDomHandle.unstable_batchedUpdates)
      expect(surface.createPortal).toBe(reactDomHandle.createPortal)
      expect(surface.flushSync).toBe(reactDomHandle.flushSync)
      expect(surface.version).toBe(reactDomHandle.version)
      expect(surface.createRoot).toBe(reactDomClientHandle.createRoot)
      expect(surface.hydrateRoot).toBe(reactDomClientHandle.hydrateRoot)
      expect(surface.ReactDOMDefault).toMatchObject({ unstable_batchedUpdates: reactDomHandle.unstable_batchedUpdates, version: reactDomHandle.version })
      expect(surface.ReactDOMClientDefault).toMatchObject({ createRoot: reactDomClientHandle.createRoot })
    } finally {
      host.window = previous
    }
  })

  it('serves the same functions the host\'s react-dom exports', () => {
    expect(reactDomHandle).toEqual({
      createPortal: ReactDOM.createPortal,
      flushSync: ReactDOM.flushSync,
      unstable_batchedUpdates: ReactDOM.unstable_batchedUpdates,
      version: ReactDOM.version,
    })
    expect(reactDomClientHandle).toEqual({ createRoot: ReactDOMClient.createRoot, hydrateRoot: ReactDOMClient.hydrateRoot })
  })

  it('still stops the build on a legacy root API, which React 19 removed', async () => {
    await expect(bundle('legacy', 'index.ts', { 'index.ts': "import { render } from 'react-dom'\nexport const x = render" }))
      .rejects.toThrow(/"render" is not exported by .*react-dom\.mjs/)
    await expect(bundle('legacy-client', 'index.ts', { 'index.ts': "import { render } from 'react-dom/client'\nexport const x = render" }))
      .rejects.toThrow(/"render" is not exported by .*react-dom-client\.mjs/)
  })

  it('fails with a clear error when the host does not serve them', async () => {
    const code = outputs.get('library') ?? await bundle('library', 'index.ts', { 'index.ts': LIBRARY })
    const file = path.join(app, 'library', 'extensions-bare.mjs')
    writeFileSync(file, code)

    const host = globalThis as unknown as { window?: unknown }
    const previous = host.window
    host.window = { Martis: { runtime: {} } }
    try {
      await expect(import(/* @vite-ignore */ pathToFileURL(file).href)).rejects.toThrow('[react-dom-shim] window.Martis.reactDom not available')
    } finally {
      host.window = previous
    }
  })
})
