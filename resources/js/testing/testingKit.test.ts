import { describe, expect, it } from 'vitest'
import { spawnSync } from 'node:child_process'
import { cpSync, mkdirSync, mkdtempSync, readFileSync, readdirSync, rmSync, symlinkSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import path from 'node:path'
import ts from 'typescript'
import { martisExtensionTestConfig } from './vitest.mjs'

/*
 * The published test kit, run as a consumer runs it: a scratch app with the
 * shims martis:install publishes, the package symlinked at
 * vendor/martis/martis (as a path repository installs it), and a Vitest run
 * with martisExtensionTestConfig() that renders a Tool through the shims.
 * It reads the committed dist/testing/, which CI rebuilds and compares.
 */

const root = process.cwd()

const TOOL = `import { useState } from 'react'
import { useAuth, useToast, api } from '@martis/runtime'
import { useTranslation } from 'react-i18next'
import { useQuery } from '@tanstack/react-query'
import { Link, useParams } from 'react-router-dom'

export default function Findings() {
  const { user } = useAuth()
  const { addToast } = useToast()
  const { t } = useTranslation('findings')
  const { findingId } = useParams()
  const count = useQuery({ queryKey: ['findings-count'], queryFn: async () => 3 })
  const [saved, setSaved] = useState(false)

  return (
    <section>
      <h1>{t('title')}</h1>
      <p>Signed in as {user?.name}</p>
      <p>Finding {findingId}</p>
      <p>{count.data ?? 'loading'} findings</p>
      <button type="button" onClick={() => { setSaved(true); addToast('success', 'Saved') }}>{saved ? 'Saved' : 'Save'}</button>
      <Link to="/findings/8">Next</Link>
      <span data-api={typeof api.get} />
    </section>
  )
}
`

const TEST = `import { afterEach, describe, expect, it } from 'vitest'
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react'
import { IconContext } from '@phosphor-icons/react'
import { iconRegistry } from '@martis/runtime'
import { MartisTestProvider } from '@martis/testing'
import Findings from '../tools/Findings'

// Vitest without \`globals\` does not register Testing Library's automatic cleanup.
afterEach(cleanup)

describe('a Tool under the Martis test kit', () => {
  it('renders with the shell providers', async () => {
    render(<MartisTestProvider translations={{ findings: { title: 'Findings' } }} path="/findings/:findingId" initialPath="/findings/7"><Findings /></MartisTestProvider>)

    expect(screen.getByRole('heading', { name: 'Findings' })).toBeTruthy()
    expect(screen.getByText('Signed in as Test User')).toBeTruthy()
    expect(screen.getByText('Finding 7')).toBeTruthy()
    expect(await screen.findByText('3 findings')).toBeTruthy()
    fireEvent.click(screen.getByRole('button', { name: 'Save' }))
    expect(screen.getByRole('button', { name: 'Saved' })).toBeTruthy()
  })

  it('navigates with the data router', async () => {
    render(<MartisTestProvider path="/findings/:findingId" initialPath="/findings/7"><Findings /></MartisTestProvider>)

    fireEvent.click(screen.getByText('Next'))
    expect(await screen.findByText('Finding 8')).toBeTruthy()
  })

  it('gives a runtime icon the app IconContext', async () => {
    const Icon = iconRegistry.resolve('acorn')
    const { container } = render(<MartisTestProvider><IconContext.Provider value={{ size: 77 }}><Icon /></IconContext.Provider></MartisTestProvider>)

    await waitFor(() => expect(container.querySelector('svg')).toBeTruthy())
    expect(container.querySelector('svg')?.getAttribute('width')).toBe('77')
  })
})
`

/** The app's vitest.config.mjs: the package at vendor/martis/martis, or at `packageDir` outside the app. */
const config = (packageDir?: string): string => `import { defineConfig, mergeConfig } from 'vitest/config'
import react from '@vitejs/plugin-react'
import { martisExtensionTestConfig } from '${packageDir === undefined ? '.' : packageDir}/${packageDir === undefined ? 'vendor/martis/martis/' : ''}dist/testing/vitest.mjs'

export default mergeConfig(
  defineConfig({ plugins: [react()], test: { include: ['resources/js/martis-extensions/**/*.test.tsx'] } }),
  martisExtensionTestConfig(${packageDir === undefined ? '' : JSON.stringify({ packageDir })}),
)
`

// Packages an app that installs its own React owns as real copies, apart from
// the package's node_modules: Testing Library imports react and react-dom from
// the real path of where it sits, so it travels with them.
const OWN_REACT = ['react', 'react-dom', 'scheduler', '@testing-library/react', '@phosphor-icons/react']

/**
 * Makes the app's own copies of React and ReactDOM report another version,
 * as a consumer that installed another major has them: the version string of
 * the development builds a test run loads, and their package.json.
 */
function reportReactVersion(app: string, version: string): void {
  for (const [name, build] of [['react', 'cjs/react.development.js'], ['react-dom', 'cjs/react-dom.development.js']]) {
    for (const file of [build, 'package.json']) {
      const target = path.join(app, 'node_modules', name, file)
      const source = readFileSync(target, 'utf8')
      const patched = file === 'package.json'
        ? source.replace(/"version": "[^"]+"/, `"version": "${version}"`)
        : source.replace(/var ReactVersion = '[^']+';/, `var ReactVersion = '${version}';`)
      if (patched === source) throw new Error(`testingKit.test.ts: no version to patch in ${target}`)
      writeFileSync(target, patched)
    }
  }
}

/**
 * Runs the consumer's Vitest in a scratch app. `packageOutside`: the app
 * passes the package, outside its root, as `packageDir`, instead of having
 * it symlinked at vendor/martis/martis.
 */
function runKit(options: { ownReact: boolean; reactVersion?: string; packageOutside?: boolean }) {
  const app = mkdtempSync(path.join(tmpdir(), 'martis-testing-kit-'))
  try {
    const extensions = path.join(app, 'resources/js/martis-extensions')
    for (const dir of ['.shims', 'tools', '__tests__']) mkdirSync(path.join(extensions, dir), { recursive: true })
    if (options.packageOutside !== true) {
      mkdirSync(path.join(app, 'vendor/martis'), { recursive: true })
      symlinkSync(root, path.join(app, 'vendor/martis/martis'))
    }
    if (options.ownReact) {
      // The package keeps its own node_modules (with a React), so the real path
      // of the symlinked package resolves a different React than the app root.
      mkdirSync(path.join(app, 'node_modules'), { recursive: true })
      for (const entry of readdirSync(path.join(root, 'node_modules'))) {
        if (entry.startsWith('@')) {
          mkdirSync(path.join(app, 'node_modules', entry), { recursive: true })
          for (const inner of readdirSync(path.join(root, 'node_modules', entry)).filter((name) => !OWN_REACT.includes(`${entry}/${name}`))) {
            symlinkSync(path.join(root, 'node_modules', entry, inner), path.join(app, 'node_modules', entry, inner))
          }
        } else if (!OWN_REACT.includes(entry)) {
          symlinkSync(path.join(root, 'node_modules', entry), path.join(app, 'node_modules', entry))
        }
      }
      for (const name of OWN_REACT) cpSync(path.join(root, 'node_modules', name), path.join(app, 'node_modules', name), { recursive: true, dereference: true })
      if (options.reactVersion !== undefined) reportReactVersion(app, options.reactVersion)
    } else {
      symlinkSync(path.join(root, 'node_modules'), path.join(app, 'node_modules'))
    }
    for (const stub of readdirSync(path.join(root, 'stubs/extensions')).filter((file) => file.endsWith('-shim.mjs.stub'))) {
      cpSync(path.join(root, 'stubs/extensions', stub), path.join(extensions, '.shims', stub.replace('-shim.mjs.stub', '.mjs')))
    }
    writeFileSync(path.join(extensions, 'tools/Findings.tsx'), TOOL)
    writeFileSync(path.join(extensions, '__tests__/Findings.test.tsx'), TEST)
    writeFileSync(path.join(app, 'vitest.config.mjs'), config(options.packageOutside === true ? root : undefined))

    const run = spawnSync(process.execPath, [path.join(root, 'node_modules/vitest/vitest.mjs'), 'run', '--config', 'vitest.config.mjs'], {
      cwd: app,
      encoding: 'utf8',
      // A clean environment: the outer Vitest's VITEST_* variables would
      // make the inner run believe it is one of its workers.
      env: { ...Object.fromEntries(Object.entries(process.env).filter(([key]) => !key.startsWith('VITEST'))), CI: 'true', NODE_ENV: 'test' },
    })

    // Without the colour codes, which split "Tests" from its count.
    return { output: `${run.stdout}\n${run.stderr}`.replace(/\u001b\[[0-9;]*m/g, ''), status: run.status }
  } finally {
    rmSync(app, { recursive: true, force: true })
  }
}

describe('the published test kit', () => {
  it('renders an extension in a consumer Vitest run, through the published shims', () => {
    const { output, status } = runKit({ ownReact: false })
    expect(output).toMatch(/Tests\s+3 passed/)
    expect(status).toBe(0)
  }, 180_000)

  it('keeps one React and one icon library when the app and the symlinked package each install their own', () => {
    const { output, status } = runKit({ ownReact: true })
    expect(output).not.toMatch(/Cannot read properties of null/)
    expect(output).toMatch(/Tests\s+3 passed/)
    expect(status).toBe(0)
  }, 180_000)

  it('runs with a packageDir outside the app root', () => {
    const { output, status } = runKit({ ownReact: true, packageOutside: true })
    expect(output).not.toMatch(/Cannot find module|Failed to resolve import/)
    expect(output).toMatch(/Tests\s+3 passed/)
    expect(status).toBe(0)
  }, 180_000)

  it('fails a run on another React major than the panel\'s, naming the install command', () => {
    // A fresh app gets React 19 from martis:install's `^18 || ^19`, while the
    // panel runs the package's React 18 (dist/testing/versions.json).
    const { output, status } = runKit({ ownReact: true, reactVersion: '19.3.0' })
    expect(output).toContain('[martis] This test run loads react 19.3.0 and react-dom 19.3.0, but the Martis panel runs React 18.3.1')
    expect(output).toContain('npm install react@^18 react-dom@^18')
    expect(output).not.toMatch(/Tests\s+\d+ passed/)
    expect(status).not.toBe(0)
  }, 180_000)
})

/** Every key path of a config object, with the kind of its value (`array`, `boolean`, `string`...). */
function runtimeShape(value: Record<string, unknown>, prefix = '', shape: Record<string, string> = {}): Record<string, string> {
  for (const [key, inner] of Object.entries(value)) {
    if (Array.isArray(inner)) shape[`${prefix}${key}`] = 'array'
    else if (inner !== null && typeof inner === 'object' && Object.getPrototypeOf(inner) === Object.prototype) runtimeShape(inner as Record<string, unknown>, `${prefix}${key}.`, shape)
    else shape[`${prefix}${key}`] = typeof inner
  }

  return shape
}

/** The same for the return type vitest.d.mts declares for martisExtensionTestConfig(). */
function declaredShape(): Record<string, string> {
  const file = path.join(root, 'resources/js/testing/vitest.d.mts')
  const program = ts.createProgram({ rootNames: [file], options: { noEmit: true, strict: true, skipLibCheck: true, types: [] } })
  const checker = program.getTypeChecker()
  const source = program.getSourceFile(file)
  const module = source === undefined ? undefined : checker.getSymbolAtLocation(source)
  const exported = module === undefined ? undefined : checker.getExportsOfModule(module).find((symbol) => symbol.name === 'martisExtensionTestConfig')
  if (source === undefined || exported === undefined) throw new Error(`${file} declares no martisExtensionTestConfig`)
  const returned = checker.getTypeOfSymbolAtLocation(exported, source).getCallSignatures()[0].getReturnType()

  const shape: Record<string, string> = {}
  const walk = (type: ts.Type, prefix: string): void => {
    for (const property of type.getProperties()) {
      const inner = checker.getTypeOfSymbolAtLocation(property, source)
      const key = `${prefix}${property.name}`
      if (checker.isArrayType(inner)) shape[key] = 'array'
      else if (inner.flags & ts.TypeFlags.BooleanLike) shape[key] = 'boolean'
      else if (inner.flags & ts.TypeFlags.StringLike) shape[key] = 'string'
      else if (inner.flags & ts.TypeFlags.Object) walk(inner, `${key}.`)
      else shape[key] = checker.typeToString(inner)
    }
  }
  walk(returned, '')

  return shape
}

describe('docs/testing-extensions.md', () => {
  const docs = readFileSync(path.join(root, 'docs/testing-extensions.md'), 'utf8')
  const versions = JSON.parse(readFileSync(path.join(root, 'dist/testing/versions.json'), 'utf8')) as Record<string, string>

  it('installs the React major and the i18next version the panel bundles', () => {
    const react = versions.react.split('.')[0]
    expect(docs).toContain(`npm install react@^${react} react-dom@^${react}`)
    expect(docs).toContain(`npm install --save-dev i18next@${versions.i18next}`)
  })
})

describe('martisExtensionTestConfig()', () => {
  /** An app root with the shims, and a package with the test runtime, as files only. */
  function scaffold(): { app: string; packageDir: string } {
    const app = mkdtempSync(path.join(tmpdir(), 'martis-testing-config-'))
    mkdirSync(path.join(app, 'resources/js/martis-extensions/.shims'), { recursive: true })
    writeFileSync(path.join(app, 'resources/js/martis-extensions/.shims/runtime.mjs'), '')
    const packageDir = path.join(app, 'vendor/martis/martis')
    mkdirSync(path.join(packageDir, 'dist/testing'), { recursive: true })
    writeFileSync(path.join(packageDir, 'dist/testing/testing.mjs'), '')

    return { app, packageDir }
  }

  it('returns exactly the keys vitest.d.mts declares', () => {
    const { app } = scaffold()
    try {
      const shape = runtimeShape(martisExtensionTestConfig({ root: app }) as unknown as Record<string, unknown>)
      expect(Object.keys(shape)).toContain('resolve.dedupe')
      expect(Object.keys(shape)).toContain('test.server.deps.inline')
      expect(declaredShape()).toEqual(shape)
    } finally {
      rmSync(app, { recursive: true, force: true })
    }
  })

  it('lets Vite serve the app root and the package, wherever it lives', () => {
    const { app, packageDir } = scaffold()
    const outside = mkdtempSync(path.join(tmpdir(), 'martis-testing-package-'))
    try {
      mkdirSync(path.join(outside, 'dist/testing'), { recursive: true })
      writeFileSync(path.join(outside, 'dist/testing/testing.mjs'), '')

      expect(martisExtensionTestConfig({ root: app }).server.fs.allow).toEqual([app, packageDir])
      expect(martisExtensionTestConfig({ root: app, packageDir: outside }).server.fs.allow).toEqual([app, outside])
    } finally {
      rmSync(app, { recursive: true, force: true })
      rmSync(outside, { recursive: true, force: true })
    }
  })

  it('resolves React, ReactDOM and the icon library from the app, whatever the package holds', () => {
    const { app } = scaffold()
    try {
      expect(martisExtensionTestConfig({ root: app }).resolve.dedupe).toEqual(['react', 'react-dom', '@phosphor-icons/react'])
    } finally {
      rmSync(app, { recursive: true, force: true })
    }
  })

  it('throws, naming the path and the fix, when the runtime or the shims are missing', () => {
    const { app, packageDir } = scaffold()
    try {
      rmSync(path.join(app, 'resources/js/martis-extensions/.shims/runtime.mjs'))
      expect(() => martisExtensionTestConfig({ root: app })).toThrow(`no shims at ${path.join(app, 'resources/js/martis-extensions/.shims')}. Run php artisan martis:install`)
      rmSync(path.join(packageDir, 'dist/testing/testing.mjs'))
      expect(() => martisExtensionTestConfig({ root: app })).toThrow(`no test runtime at ${path.join(packageDir, 'dist/testing/testing.mjs')}. Run composer install, or pass { packageDir }`)
    } finally {
      rmSync(app, { recursive: true, force: true })
    }
  })
})
