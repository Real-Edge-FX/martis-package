import { afterEach, describe, expect, it, vi } from 'vitest'
import { isValidElement } from 'react'
import { render, screen } from '@testing-library/react'
import { createMemoryRouter, matchRoutes, RouterProvider, useParams, type RouteObject } from 'react-router'

vi.mock('@/components/Sidebar', () => ({ Sidebar: () => <nav>Sidebar</nav> }))
vi.mock('@/components/Topbar', () => ({ Topbar: () => <header>Topbar</header> }))
vi.mock('@/components/Footer', () => ({ Footer: () => <footer>Footer</footer> }))
vi.mock('@/components/ImpersonationBanner', () => ({ ImpersonationBanner: () => null }))
vi.mock('@/components/KeyboardShortcutsHelp', () => ({ KeyboardShortcutsHelp: () => null }))
vi.mock('@/components/NavigationProgress', () => ({ NavigationProgress: () => null }))
vi.mock('@/components/MartisTooltip', () => ({ MartisTooltip: () => null }))
vi.mock('@/contexts/AuthContext', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/contexts/AuthContext')>()),
  useAuth: () => ({ user: { id: 1, name: 'Ada', email: 'ada@example.com', panel_access: true }, isLoading: false }),
}))

import { componentRegistry } from '@/lib/componentRegistry'
import { RESERVED_ROUTE_SEGMENTS, RouteRegistry } from '@/lib/routeRegistry'
import { config } from '@/lib/config'
import * as routerModule from '@/router'
import { buildAppRoutes, createAppRouter } from '@/router'

const AUTH_PAGES: [string, string][] = [
  ['auth:login', '/login'],
  ['auth:register', '/register'],
  ['auth:forgot-password', '/forgot-password'],
  ['auth:reset-password', '/reset-password/:token'],
  ['auth:email-verify-notice', '/email/verify'],
  ['auth:invitation-accept', '/invitations/accept/:token'],
]

function FindingPage() {
  const { findingId } = useParams<{ findingId: string }>()
  return <p>Finding {findingId}</p>
}

function shellChildren(routes: RouteObject[]): RouteObject[] {
  return routes.find((route) => route.path === '/')!.children!
}

function renderAt(routes: RouteObject[], path: string) {
  const router = createMemoryRouter(routes, { initialEntries: [path] })
  render(<RouterProvider router={router} />)
  return router
}

afterEach(() => {
  for (const [key] of AUTH_PAGES) componentRegistry.unregister(key)
  vi.restoreAllMocks()
})

describe('auth page overrides', () => {
  it('renders an override an extension registered before the router was built', async () => {
    componentRegistry.register('auth:login', (() => <p>Custom sign-in</p>) as never)

    renderAt(buildAppRoutes([]), '/login')

    expect(await screen.findByText('Custom sign-in')).toBeTruthy()
  })

  it.each(AUTH_PAGES)('resolves %s on %s when the router is built', (key, path) => {
    function Override() {
      return null
    }
    componentRegistry.register(key, Override as never)

    const route = buildAppRoutes([]).find((candidate) => candidate.path === path)!

    expect(isValidElement(route.element) && route.element.type).toBe(Override)
  })
})

describe('the app router', () => {
  it('is not built when its module is imported', () => {
    expect(routerModule).not.toHaveProperty('router')
  })

  it('puts the registered routes after the package routes and before the catch-all', () => {
    const registry = new RouteRegistry()
    registry.register({ path: 'findings/:findingId', component: FindingPage })

    const paths = shellChildren(buildAppRoutes(registry.routes())).map((route) => route.path ?? '(pathless)')

    expect(paths[paths.length - 1]).toBe('*')
    expect(paths[paths.length - 2]).toBe('findings/:findingId')
    expect(paths.indexOf('findings/:findingId')).toBeGreaterThan(paths.indexOf('500'))
  })

  it('matches a registered path, leaves the rest to the catch-all and keeps the package routes', () => {
    vi.spyOn(console, 'error').mockImplementation(() => {})
    const registry = new RouteRegistry()
    registry.register({ path: 'findings/:findingId', component: FindingPage })
    expect(registry.register({ path: 'tools/:uriKey', component: FindingPage })).toBe(false)

    const routes = buildAppRoutes(registry.routes())
    const leaf = (path: string) => {
      const matches = matchRoutes(routes, path)!
      return matches[matches.length - 1]!.route.path
    }

    expect(leaf('/findings/0192f7c1')).toBe('findings/:findingId')
    expect(leaf('/projects/1/repositories')).toBe('*')
    expect(leaf('/tools/findings')).toBe('tools/:uriKey')
  })

  it('seals the registry when it builds the router', () => {
    vi.spyOn(console, 'error').mockImplementation(() => {})
    const registry = new RouteRegistry()

    const router = createAppRouter(registry)

    expect(registry.register({ path: 'findings', component: FindingPage })).toBe(false)
    router.dispose()
  })

  it('renders a registered page inside the shell, with its crumb label on the route', async () => {
    const registry = new RouteRegistry()
    registry.register({ path: 'findings/:findingId', component: FindingPage, crumb: 'Findings' })

    const router = renderAt(buildAppRoutes(registry.routes()), '/findings/0192f7c1')

    expect(await screen.findByText('Finding 0192f7c1')).toBeTruthy()
    expect(screen.getByText('Sidebar')).toBeTruthy()
    expect(screen.getByText('Topbar')).toBeTruthy()
    expect(screen.getByText('Footer')).toBeTruthy()
    expect(router.state.matches[router.state.matches.length - 1]!.route.handle).toEqual({ crumbLabel: 'Findings' })
  })

  it('still renders the 404 page inside the shell for a path nothing registered', async () => {
    renderAt(buildAppRoutes([]), '/projects/1/repositories')

    expect(await screen.findByText('Resource not found')).toBeTruthy()
    expect(screen.getByText('Sidebar')).toBeTruthy()
  })

  it('resolves two routes React Router ranks equal the same way in any registration order', () => {
    // `a/:x/c` and `a/b/:y` both score 26 on `/a/b/c`, so the earlier
    // sibling wins. Registered from two bundles, the registration order is
    // the network's; the router must not follow it.
    const leafFor = (paths: string[]) => {
      const registry = new RouteRegistry()
      for (const path of paths) registry.register({ path, component: FindingPage })
      const matches = matchRoutes(buildAppRoutes(registry.routes()), '/a/b/c')!
      return matches[matches.length - 1]!.route.path
    }

    expect(leafFor(['a/:x/c', 'a/b/:y'])).toBe(leafFor(['a/b/:y', 'a/:x/c']))
  })
})

describe('the reserved first segments', () => {
  const dev = config.dev

  afterEach(() => {
    config.dev = dev
  })

  it('include the first segment of every package route, dev-only routes too', () => {
    config.dev = { ...config.dev, toolsEnabled: true }
    const firsts = new Set<string>()
    const walk = (routes: RouteObject[]) => {
      for (const route of routes) {
        const first = route.path?.replace(/^\//, '').split('/')[0]
        if (first !== undefined && first !== '' && first !== '*') firsts.add(first.toLowerCase())
        if (route.children) walk(route.children)
      }
    }

    walk(buildAppRoutes([]))

    // The walk must have seen the package's routes, or it proves nothing.
    expect([...firsts]).toEqual(expect.arrayContaining(['tools', 'resources', 'login', 'dev']))
    expect([...firsts].filter((first) => !RESERVED_ROUTE_SEGMENTS.includes(first))).toEqual([])
  })
})
