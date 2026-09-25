import { describe, it, expect, vi, afterEach } from 'vitest'
import { render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router'

/*
 * The global tooltip (`MartisTooltip`) turns every `data-pr-tooltip` into
 * a tooltip. It was mounted only inside the bundled layout presets, so a
 * shell override (`layout:shell`, or the key `martis.layout.components.shell`
 * names) showed none. It is mounted once, above the shell switch, now:
 * exactly one on every shell, presets included.
 */

const configState = vi.hoisted(() => ({ layout: {} as Record<string, unknown> }))

vi.mock('@/components/MartisTooltip', () => ({
  MartisTooltip: () => <div data-testid="martis-tooltip" />,
}))

// The presets' chrome is not under test: stand-ins keep them renderable.
vi.mock('@/components/Sidebar', () => ({ Sidebar: () => <nav /> }))
vi.mock('@/components/Topbar', () => ({ Topbar: () => <header /> }))
vi.mock('@/components/Footer', () => ({ Footer: () => <footer /> }))
vi.mock('@/components/ImpersonationBanner', () => ({ ImpersonationBanner: () => null }))
vi.mock('@/components/KeyboardShortcutsHelp', () => ({ KeyboardShortcutsHelp: () => null }))
vi.mock('@/components/NavigationProgress', () => ({ NavigationProgress: () => null }))
vi.mock('@/components/layouts/TopnavLayout', () => ({ TopnavLayout: () => <div>Topnav preset</div> }))
vi.mock('@/components/layouts/MinimalLayout', () => ({ MinimalLayout: () => <div>Minimal preset</div> }))

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
    expect(screen.getAllByTestId('martis-tooltip')).toHaveLength(1)
  })

  it('gets the global tooltip when named by martis.layout.components.shell', () => {
    componentRegistry.register('my-app:shell', CustomShell)
    configState.layout = { components: { shell: 'my-app:shell' } }

    render(<MemoryRouter><Layout /></MemoryRouter>)

    expect(screen.getByText('Custom shell')).toBeTruthy()
    expect(screen.getAllByTestId('martis-tooltip')).toHaveLength(1)
  })
})

describe('a layout preset', () => {
  it.each([
    ['sidebar', null],
    ['topnav', 'Topnav preset'],
    ['minimal', 'Minimal preset'],
    ['custom', 'Layout preset is'],
  ])('%s gets the global tooltip, once', (preset, text) => {
    configState.layout = { preset }

    const { container } = render(<MemoryRouter><Layout /></MemoryRouter>)

    if (text !== null) expect(screen.getByText(text)).toBeTruthy()
    else expect(container.querySelector('nav')).not.toBeNull()
    expect(screen.getAllByTestId('martis-tooltip')).toHaveLength(1)
  })
})
