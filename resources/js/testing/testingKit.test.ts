import { describe, expect, it } from 'vitest'
import { spawnSync } from 'node:child_process'
import { cpSync, mkdirSync, mkdtempSync, readdirSync, rmSync, symlinkSync, writeFileSync } from 'node:fs'
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

describe('the published test kit', () => {
  it('renders an extension in a consumer Vitest run, through the published shims', () => {
    const app = mkdtempSync(path.join(tmpdir(), 'martis-testing-kit-'))
    try {
      const extensions = path.join(app, 'resources/js/martis-extensions')
      for (const dir of ['.shims', 'tools', '__tests__']) mkdirSync(path.join(extensions, dir), { recursive: true })
      mkdirSync(path.join(app, 'vendor/martis'), { recursive: true })
      symlinkSync(root, path.join(app, 'vendor/martis/martis'))
      symlinkSync(path.join(root, 'node_modules'), path.join(app, 'node_modules'))
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
      const output = `${run.stdout}\n${run.stderr}`.replace(/\u001b\[[0-9;]*m/g, '')
      expect(output).toMatch(/Tests\s+2 passed/)
      expect(run.status).toBe(0)
    } finally {
      rmSync(app, { recursive: true, force: true })
    }
  }, 180_000)
})
