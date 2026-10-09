import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'

/*
 * The sidebar layout's keyboard and screen-reader contract (v2.7.0): the
 * collapsed rail names its links, the mobile drawer is a modal dialog, a
 * second skip link reaches the navigation, `martis.layout.header_first`
 * puts the top bar first in the Tab order, and the top bar renders the
 * `topbar:start` / `topbar:end` slots. The real Sidebar and Topbar render;
 * their data and popups are stand-ins.
 */

const state = vi.hoisted(() => ({
  layout: {} as Record<string, unknown>,
  mobile: false,
}))

vi.mock('@/lib/config', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/config')>()
  return { ...actual, config: new Proxy(actual.config, { get: (target, key) => (key === 'layout' ? state.layout : Reflect.get(target, key)) }) }
})

vi.mock('@/hooks/useIsMobile', () => ({ useIsMobile: () => state.mobile }))

vi.mock('@/lib/api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/api')>()
  const payloads: Record<string, unknown> = {
    '/api/navigation': [
      {
        label: 'Content',
        items: [
          { type: 'resource', uriKey: 'posts', label: 'Posts', url: '/resources/posts', icon: 'article' },
          { type: 'link', label: 'Guide', url: 'https://example.com/guide', icon: null, external: true },
        ],
      },
    ],
    '/api/dashboards': { data: { dashboards: [{ uriKey: 'main', name: 'Overview', parent: null, icon: null, lock: null, badge: null }] } },
    '/api/navigation/badges': {},
  }
  return { ...actual, api: { ...actual.api, get: vi.fn((url: string) => Promise.resolve(payloads[url] ?? {})) } }
})

vi.mock('@/contexts/AuthContext', () => ({
  useAuth: () => ({ user: { id: 1, name: 'Ada', email: 'ada@example.com' }, isLoading: false, logout: vi.fn() }),
}))
vi.mock('@/components/NotificationBell', () => ({ NotificationBell: () => <button type="button">Notifications</button> }))
vi.mock('@/components/PreferencesMenu', async () => {
  const { forwardRef } = await import('react')
  return { PreferencesMenu: forwardRef(function PreferencesMenu() { return <button type="button">Preferences</button> }) }
})
vi.mock('@/components/GlobalSearch', () => ({ GlobalSearch: () => null }))
vi.mock('@/components/Breadcrumbs', () => ({ Breadcrumbs: () => <nav aria-label="Breadcrumbs" /> }))
vi.mock('@/components/Footer', () => ({ Footer: () => <footer /> }))
vi.mock('@/components/ImpersonationBanner', () => ({ ImpersonationBanner: () => null }))
vi.mock('@/components/KeyboardShortcutsHelp', () => ({ KeyboardShortcutsHelp: () => null }))
vi.mock('@/components/NavigationProgress', () => ({ NavigationProgress: () => null }))
vi.mock('@/components/MartisTooltip', () => ({ MartisTooltip: () => null }))

import { Layout } from './Layout'
import { componentRegistry } from '@/lib/componentRegistry'

async function renderShell() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  const view = render(
    <QueryClientProvider client={client}>
      <MemoryRouter>
        <Layout />
      </MemoryRouter>
    </QueryClientProvider>,
  )
  // The menu is loaded: the resource link is rendered (named, collapsed or not).
  await screen.findByRole('link', { name: 'Posts' })

  return view
}

/** The elements Tab visits, in order, starting from the top of the page. */
async function tabOrder(user: ReturnType<typeof userEvent.setup>, stops: number): Promise<Element[]> {
  const visited: Element[] = []
  for (let i = 0; i < stops; i++) {
    await user.tab()
    if (document.activeElement !== null) visited.push(document.activeElement)
  }
  return visited
}

beforeEach(() => {
  state.layout = {}
  state.mobile = false
  localStorage.removeItem('martis-sidebar-collapsed')
})

afterEach(() => {
  for (const key of ['topbar:start', 'topbar:end', 'my-tenant']) componentRegistry.unregister(key)
})

describe('the collapsed sidebar rail', () => {
  it('names every icon-only link by its label and keeps the tooltip', async () => {
    localStorage.setItem('martis-sidebar-collapsed', 'true')
    await renderShell()

    const navigation = screen.getByRole('navigation', { name: 'Main navigation' })
    for (const name of ['Overview', 'Posts', 'Guide']) {
      const link = within(navigation).getByRole('link', { name })
      expect(link.querySelector('.martis-sb-item-label')).toBeNull()
      expect(link.getAttribute('data-pr-tooltip')).toBe(name)
    }
  })

  it('names the links by their visible label when expanded, without an aria-label', async () => {
    await renderShell()

    // The desktop sidebar is never inert.
    expect(document.getElementById('martis-sidebar')?.hasAttribute('inert')).toBe(false)

    const link = screen.getByRole('link', { name: 'Posts' })
    expect(link.getAttribute('aria-label')).toBeNull()
    expect(link.querySelector('.martis-sb-item-label')?.textContent).toBe('Posts')
  })
})

describe('the group chevrons', () => {
  it('are named after their group and report whether it is expanded', async () => {
    const user = userEvent.setup()
    await renderShell()

    const toggle = screen.getByRole('button', { name: 'Collapse Content' })
    expect(toggle.getAttribute('aria-expanded')).toBe('true')

    await user.click(toggle)

    expect(screen.getByRole('button', { name: 'Expand Content' }).getAttribute('aria-expanded')).toBe('false')
  })
})

describe('the mobile drawer', () => {
  beforeEach(() => {
    state.mobile = true
  })

  it('is a modal dialog that keeps focus and hands it back to the menu button', async () => {
    const user = userEvent.setup()
    await renderShell()

    const menuButton = screen.getByRole('button', { name: 'Menu' })
    expect(menuButton.getAttribute('aria-expanded')).toBe('false')
    expect(menuButton.getAttribute('aria-controls')).toBe('martis-sidebar')
    expect(screen.queryByRole('dialog')).toBeNull()
    // Closed, the drawer is inert: out of the Tab order and the accessibility tree.
    expect(document.getElementById('martis-sidebar')?.getAttribute('inert')).toBe('')

    await user.click(menuButton)

    const dialog = screen.getByRole('dialog', { name: 'Main navigation' })
    expect(dialog.hasAttribute('inert')).toBe(false)
    expect(dialog.id).toBe('martis-sidebar')
    expect(dialog.getAttribute('aria-modal')).toBe('true')
    expect(menuButton.getAttribute('aria-expanded')).toBe('true')
    // Focus moved to the drawer's first focusable element.
    expect(document.activeElement).toBe(within(dialog).getByRole('link', { name: 'Overview' }))

    for (let i = 0; i < 30; i++) {
      await user.tab()
      expect(dialog.contains(document.activeElement)).toBe(true)
    }
    for (let i = 0; i < 7; i++) {
      await user.tab({ shift: true })
      expect(dialog.contains(document.activeElement)).toBe(true)
    }

    await user.keyboard('{Escape}')

    expect(screen.queryByRole('dialog')).toBeNull()
    expect(document.activeElement).toBe(menuButton)
    expect(menuButton.getAttribute('aria-expanded')).toBe('false')
    expect(document.getElementById('martis-sidebar')?.hasAttribute('inert')).toBe(true)
  })

  it('leaves Escape and Tab to a surface opened over it, such as the command palette', async () => {
    const user = userEvent.setup()
    await renderShell()
    await user.click(screen.getByRole('button', { name: 'Menu' }))

    // A palette portalled to the body, outside the drawer, takes focus.
    const palette = document.createElement('input')
    palette.setAttribute('aria-label', 'Palette')
    document.body.appendChild(palette)
    palette.focus()

    await user.keyboard('{Escape}')
    await user.tab()

    expect(screen.getByRole('dialog')).toBeTruthy()
    expect(screen.getByRole('dialog').contains(document.activeElement)).toBe(false)
    palette.remove()
  })

  it('returns focus to the menu button when the backdrop closes it', async () => {
    const user = userEvent.setup()
    const { container } = await renderShell()
    const menuButton = screen.getByRole('button', { name: 'Menu' })

    await user.click(menuButton)
    await user.click(container.querySelector('.martis-shell-backdrop')!)

    expect(screen.queryByRole('dialog')).toBeNull()
    expect(document.activeElement).toBe(menuButton)
  })

  it('returns focus to the menu button when a link of the drawer navigates', async () => {
    const user = userEvent.setup()
    await renderShell()
    const menuButton = screen.getByRole('button', { name: 'Menu' })

    await user.click(menuButton)
    await user.click(within(screen.getByRole('dialog')).getByRole('link', { name: 'Posts' }))

    expect(screen.queryByRole('dialog')).toBeNull()
    expect(document.activeElement).toBe(menuButton)
  })
})

describe('the skip link to the navigation', () => {
  it('is the second Tab stop and moves focus to the navigation landmark', async () => {
    const user = userEvent.setup()
    await renderShell()

    const [first, second] = await tabOrder(user, 2)
    expect(first.textContent).toBe('Skip to main content')
    expect(second.textContent).toBe('Skip to navigation')

    await user.keyboard('{Enter}')

    expect(document.activeElement?.id).toBe('martis-navigation')
    expect(document.activeElement?.tagName).toBe('NAV')
  })

  it('moves focus to the menu button on mobile, where the menu is a closed drawer', async () => {
    state.mobile = true
    const user = userEvent.setup()
    await renderShell()

    await tabOrder(user, 2)
    await user.keyboard('{Enter}')

    expect(document.activeElement).toBe(screen.getByRole('button', { name: 'Menu' }))
  })
})

describe('martis.layout.header_first', () => {
  async function stopsByRegion(): Promise<string[]> {
    const user = userEvent.setup()
    const { container } = await renderShell()
    const topbar = container.querySelector('.martis-tb')!
    const sidebar = container.querySelector('.martis-sb')!
    const visited = await tabOrder(user, 14)

    return visited.map((element) => (topbar.contains(element) ? 'topbar' : sidebar.contains(element) ? 'sidebar' : 'other'))
  }

  it('keeps the sidebar before the top bar by default', async () => {
    const regions = await stopsByRegion()

    expect(regions.slice(0, 2)).toEqual(['other', 'other'])
    expect(regions.indexOf('sidebar')).toBeLessThan(regions.indexOf('topbar'))
    expect(regions.lastIndexOf('sidebar')).toBeLessThan(regions.indexOf('topbar'))
  })

  it('puts the top bar before the sidebar in the DOM and the Tab order when true', async () => {
    state.layout = { header_first: true }
    const regions = await stopsByRegion()

    expect(regions.slice(0, 2)).toEqual(['other', 'other'])
    expect(regions[2]).toBe('topbar')
    expect(regions.lastIndexOf('topbar')).toBeLessThan(regions.indexOf('sidebar'))
  })
})

describe('the top-bar slots', () => {
  function precedes(a: Element, b: Element): boolean {
    return (a.compareDocumentPosition(b) & Node.DOCUMENT_POSITION_FOLLOWING) !== 0
  }

  it('render topbar:start after the collapse button, before the search, and topbar:end after the preferences menu', async () => {
    componentRegistry.register('topbar:start', () => <span>Tenant: Acme</span>)
    componentRegistry.register('topbar:end', () => <span>Status: online</span>)
    const { container } = await renderShell()

    const start = screen.getByText('Tenant: Acme')
    const end = screen.getByText('Status: online')
    const collapse = container.querySelector('.martis-tb-collapse-btn')!
    const search = container.querySelector('.martis-tb-search')!
    const preferences = screen.getByRole('button', { name: 'Preferences' })
    const userMenu = container.querySelector('.martis-tb-user')!

    expect(precedes(collapse, start)).toBe(true)
    expect(precedes(start, search)).toBe(true)
    expect(precedes(preferences, end)).toBe(true)
    expect(precedes(end, userMenu)).toBe(true)
  })

  it('render the component the config key names', async () => {
    componentRegistry.register('my-tenant', () => <span>Configured tenant</span>)
    state.layout = { components: { topbar_start: 'my-tenant' } }
    await renderShell()

    expect(screen.getByText('Configured tenant')).toBeTruthy()
  })

  it('render nothing when nothing is registered', async () => {
    const { container } = await renderShell()

    const collapse = container.querySelector('.martis-tb-collapse-btn')!
    expect(collapse.nextElementSibling?.className).toBe('martis-tb-breadcrumbs')
  })
})
