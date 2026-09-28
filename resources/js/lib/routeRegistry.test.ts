import { afterEach, beforeEach, describe, expect, it, vi, type MockInstance } from 'vitest'
import { memo } from 'react'
import { crumbLabelFor, RESERVED_ROUTE_SEGMENTS, RouteRegistry, type RegisteredRoute } from '@/lib/routeRegistry'

function Page() {
  return null
}

let error: MockInstance

beforeEach(() => {
  error = vi.spyOn(console, 'error').mockImplementation(() => {})
})

afterEach(() => {
  error.mockRestore()
})

/** Assert one refusal: `false`, and a console error that names the reason. */
function expectRefused(registry: RouteRegistry, route: Parameters<RouteRegistry['register']>[0], reason: RegExp): void {
  expect(registry.register(route)).toBe(false)
  expect(error).toHaveBeenCalledTimes(1)
  expect(String(error.mock.calls[0]![0])).toMatch(/^\[martis\] routeRegistry: /)
  expect(String(error.mock.calls[0]![0])).toMatch(reason)
  error.mockClear()
}

describe('RouteRegistry', () => {
  it('accepts a static path and ignores the slashes around it', () => {
    const registry = new RouteRegistry()

    expect(registry.register({ path: '/findings/', component: Page })).toBe(true)

    expect(registry.routes()).toEqual([{ path: 'findings', component: Page, crumb: null, tool: null }])
    expect(error).not.toHaveBeenCalled()
  })

  it('accepts parameters and a final splat', () => {
    const registry = new RouteRegistry()

    expect(registry.register({ path: 'projects/:projectId/repositories', component: Page })).toBe(true)
    expect(registry.register({ path: 'handbook/*', component: Page })).toBe(true)

    expect(registry.routes().map((route) => route.path)).toEqual(['projects/:projectId/repositories', 'handbook/*'])
  })

  it.each(['', '/', 'a//b', 'a/:id?', 'a/b c', 'a/%20', 'a#b', 'a/*/b', 'a/..', '.'])('refuses the path %j', (path) => {
    expectRefused(new RouteRegistry(), { path, component: Page }, /empty|not a valid segment/)
  })

  it.each([':slug', ':org/repositories', '*'])('refuses the path %j, whose first segment is not static text', (path) => {
    expectRefused(new RouteRegistry(), { path, component: Page }, /first segment .* must be static text/)
  })

  it('refuses every reserved first segment, in any letter case', () => {
    for (const segment of RESERVED_ROUTE_SEGMENTS) {
      expectRefused(new RouteRegistry(), { path: `${segment}/extra`, component: Page }, /is reserved by Martis/)
      expectRefused(new RouteRegistry(), { path: segment.toUpperCase(), component: Page }, /is reserved by Martis/)
    }
  })

  it('reserves the shell pages, the account pages and the server routes', () => {
    expect([...RESERVED_ROUTE_SEGMENTS].sort()).toEqual([
      '2fa', '403', '500', 'api', 'dashboards', 'dev', 'email', 'favicon.ico', 'forgot-password',
      'invitations', 'login', 'logout', 'profile', 'register', 'reset-password', 'resources', 'sso',
      'system', 'tools',
    ])
    expect(Object.isFrozen(RESERVED_ROUTE_SEGMENTS)).toBe(true)
  })

  it('refuses a second route of the same shape and keeps the first', () => {
    const registry = new RouteRegistry()
    function Other() {
      return null
    }

    expect(registry.register({ path: 'findings/:id', component: Page })).toBe(true)
    expectRefused(registry, { path: 'Findings/:findingId', component: Other }, /already registered/)

    expect(registry.routes()).toHaveLength(1)
    expect(registry.routes()[0]!.component).toBe(Page)
  })

  it('answers has() by shape', () => {
    const registry = new RouteRegistry()
    registry.register({ path: 'findings/:id', component: Page })

    expect(registry.has('/findings/:findingId')).toBe(true)
    expect(registry.has('findings')).toBe(false)
  })

  it('accepts a componentRegistry key, a function component and a memo component', () => {
    const registry = new RouteRegistry()

    expect(registry.register({ path: 'a', component: 'page:a' })).toBe(true)
    expect(registry.register({ path: 'b', component: Page })).toBe(true)
    expect(registry.register({ path: 'c', component: memo(Page) })).toBe(true)
  })

  it.each([[42], [''], [{}], [null]])('refuses the component %j', (component) => {
    expectRefused(new RouteRegistry(), { path: 'findings', component: component as never }, /component must be/)
  })

  it('refuses an empty tool or crumb, and trims the ones it keeps', () => {
    const registry = new RouteRegistry()

    expectRefused(registry, { path: 'a', component: Page, tool: ' ' }, /tool must be/)
    expectRefused(registry, { path: 'a', component: Page, crumb: '' }, /crumb must be/)
    expect(registry.register({ path: 'a', component: Page, tool: ' findings ', crumb: ' Findings ' })).toBe(true)

    expect(registry.routes()[0]).toMatchObject({ tool: 'findings', crumb: 'Findings' })
  })

  it('refuses every registration once sealed', () => {
    const registry = new RouteRegistry()
    registry.register({ path: 'findings', component: Page })
    registry.seal()

    expectRefused(registry, { path: 'reports', component: Page }, /registered when the extension bundle loads \(at its top level\): the router was already built/)
    expect(registry.routes()).toHaveLength(1)
  })

  it('refuses a registration that is not an object', () => {
    expectRefused(new RouteRegistry(), null as never, /a route is an object/)
  })
})

describe('crumbLabelFor', () => {
  const route = (path: string, crumb: string | null = null): RegisteredRoute => ({ path, component: Page, crumb, tool: null })

  it('uses the crumb when one was given', () => {
    expect(crumbLabelFor(route('findings/:id', 'Open findings'))).toBe('Open findings')
  })

  it.each([
    ['findings', 'Findings'],
    ['project-reports/:id', 'Project reports'],
    ['audit_log', 'Audit log'],
  ])('derives the label of %j from its first segment: %j', (path, label) => {
    expect(crumbLabelFor(route(path))).toBe(label)
  })
})
