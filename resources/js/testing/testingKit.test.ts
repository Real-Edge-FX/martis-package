import { describe, expect, it } from 'vitest'
import { spawnSync } from 'node:child_process'
import { cpSync, mkdirSync, mkdtempSync, readFileSync, readdirSync, rmSync, symlinkSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import path from 'node:path'

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
import { cleanup, fireEvent, render, screen } from '@testing-library/react'
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
})
`

const CONFIG = `import { defineConfig, mergeConfig } from 'vitest/config'
import react from '@vitejs/plugin-react'
import { martisExtensionTestConfig } from './vendor/martis/martis/dist/testing/vitest.mjs'

export default mergeConfig(
  defineConfig({ plugins: [react()], test: { include: ['resources/js/martis-extensions/**/*.test.tsx'] } }),
  martisExtensionTestConfig(),
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

function runKit(options: { ownReact: boolean; reactVersion?: string }) {
  const app = mkdtempSync(path.join(tmpdir(), 'martis-testing-kit-'))
  try {
    const extensions = path.join(app, 'resources/js/martis-extensions')
    for (const dir of ['.shims', 'tools', '__tests__']) mkdirSync(path.join(extensions, dir), { recursive: true })
    mkdirSync(path.join(app, 'vendor/martis'), { recursive: true })
    symlinkSync(root, path.join(app, 'vendor/martis/martis'))
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
    writeFileSync(path.join(app, 'vitest.config.mjs'), CONFIG)

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
    expect(output).toMatch(/Tests\s+2 passed/)
    expect(status).toBe(0)
  }, 180_000)

  it('keeps one React when the app and the symlinked package each install their own', () => {
    const { output, status } = runKit({ ownReact: true })
    expect(output).not.toMatch(/Cannot read properties of null/)
    expect(output).toMatch(/Tests\s+2 passed/)
    expect(status).toBe(0)
  }, 180_000)

  it('fails a run on another React major than the panel\'s, naming the install command', () => {
    // A fresh app gets React 19 from martis:install's `^18 || ^19`, while the
    // panel runs the package's React 18 (dist/testing/versions.json).
    const { output, status } = runKit({ ownReact: true, reactVersion: '19.3.0' })
    expect(output).toContain('[martis] This test run loads react 19.3.0 and react-dom 19.3.0, but the Martis panel runs React 18.3.1')
    expect(output).toContain('npm install react@^18 react-dom@^18')
    expect(output).not.toMatch(/Tests\s+2 passed/)
    expect(status).not.toBe(0)
  }, 180_000)
})
