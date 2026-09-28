import { afterEach, describe, expect, it, vi } from 'vitest'
import { isValidElement } from 'react'
import { matchRoutes, type RouteObject } from 'react-router'
import { bootAppRouter } from '@/lib/appBoot'
import { componentRegistry } from '@/lib/componentRegistry'
import { RouteRegistry } from '@/lib/routeRegistry'

/*
 * The boot sequence `app.tsx` runs. Before v2.2.0 the router was built when
 * `@/router` was imported, ahead of the extension bundles, so an auth page
 * override they registered never rendered. These tests fail if the router
 * is built before the bundles finish loading.
 */

function CustomLogin() {
  return null
}

function FindingPage() {
  return null
}

/** An extension loader that registers only once its bundle "arrives". */
function slowBundle(registry: RouteRegistry) {
  return vi.fn(
    () =>
      new Promise<void>((resolve) =>
        setTimeout(() => {
          componentRegistry.register('auth:login', CustomLogin as never)
          registry.register({ path: 'findings/:findingId', component: FindingPage })
          resolve()
        }, 20),
      ),
  )
}

afterEach(() => {
  componentRegistry.unregister('auth:login')
})

describe('bootAppRouter', () => {
  it('builds the router after the extension bundles load, with their auth overrides and routes', async () => {
    const registry = new RouteRegistry()

    const router = await bootAppRouter(false, { initI18n: async () => {}, loadExtensions: slowBundle(registry), registry })

    const routes = router!.routes as RouteObject[]
    const login = routes.find((route) => route.path === '/login')!
    expect(isValidElement(login.element) && login.element.type).toBe(CustomLogin)
    const matches = matchRoutes(routes, '/findings/0192f7c1')!
    expect(matches[matches.length - 1]!.route.path).toBe('findings/:findingId')
    router!.dispose()
  })

  it('seals the registry once the router is built', async () => {
    vi.spyOn(console, 'error').mockImplementation(() => {})
    const registry = new RouteRegistry()

    const router = await bootAppRouter(false, { initI18n: async () => {}, loadExtensions: async () => {}, registry })

    expect(registry.register({ path: 'late', component: FindingPage })).toBe(false)
    router!.dispose()
    vi.restoreAllMocks()
  })

  it('still builds the router when i18n or the loader fails', async () => {
    const registry = new RouteRegistry()

    const router = await bootAppRouter(false, {
      initI18n: () => Promise.reject(new Error('i18n down')),
      loadExtensions: () => Promise.reject(new Error('bundle down')),
      registry,
    })

    expect(router).not.toBeNull()
    router!.dispose()
  })

  it('loads no bundle and builds no router for a user the panel gate refuses', async () => {
    const registry = new RouteRegistry()
    const loadExtensions = slowBundle(registry)

    const router = await bootAppRouter(true, { initI18n: async () => {}, loadExtensions, registry })

    expect(router).toBeNull()
    expect(loadExtensions).not.toHaveBeenCalled()
  })
})
