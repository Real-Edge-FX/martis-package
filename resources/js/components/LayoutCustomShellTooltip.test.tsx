import { describe, it, expect, vi, afterEach } from 'vitest'
import { render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'

/*
 * The global tooltip (`MartisTooltip`) turns every `data-pr-tooltip` into
 * a tooltip. It was mounted only inside the bundled layout presets, so a
 * shell override (`layout:shell`, or the key `martis.layout.components.shell`
 * names) showed none. It is mounted above the shell switch now.
 */

const configState = vi.hoisted(() => ({ layout: {} as Record<string, unknown> }))

vi.mock('@/components/MartisTooltip', () => ({
  MartisTooltip: () => <div data-testid="martis-tooltip" />,
}))

vi.mock('@/contexts/AuthContext', () => ({
  useAuth: () => ({ user: { id: 1, name: 'Ada' }, isLoading: false }),
}))

vi.mock('@/lib/config', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/config')>()
  return { ...actual, config: new Proxy(actual.config, { get: (target, key) => (key === 'layout' ? configState.layout : Reflect.get(target, key)) }) }
})

import { Layout } from './Layout'
import { componentRegistry } from '@/lib/componentRegistry'

function CustomShell() {
  return <button type="button" data-pr-tooltip="Custom tooltip">Custom shell</button>
}

afterEach(() => {
  componentRegistry.unregister('layout:shell')
  componentRegistry.unregister('my-app:shell')
  configState.layout = {}
})

describe('a custom layout shell', () => {
  it('gets the global tooltip when registered as layout:shell', () => {
    componentRegistry.register('layout:shell', CustomShell)

    render(<MemoryRouter><Layout /></MemoryRouter>)

    expect(screen.getByText('Custom shell')).toBeTruthy()
    expect(screen.getByTestId('martis-tooltip')).toBeTruthy()
  })

  it('gets the global tooltip when named by martis.layout.components.shell', () => {
    componentRegistry.register('my-app:shell', CustomShell)
    configState.layout = { components: { shell: 'my-app:shell' } }

    render(<MemoryRouter><Layout /></MemoryRouter>)

    expect(screen.getByText('Custom shell')).toBeTruthy()
    expect(screen.getByTestId('martis-tooltip')).toBeTruthy()
  })
})
