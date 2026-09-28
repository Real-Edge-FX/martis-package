import { initI18n } from '@/lib/i18n'
import { loadConsumerExtensions } from '@/lib/extensionLoader'
import { routeRegistry, type RouteRegistry } from '@/lib/routeRegistry'
import { createAppRouter, type AppRouter } from '@/router'

/** What `bootAppRouter()` waits on; replaced in tests. */
export interface AppBootDependencies {
  initI18n?: () => Promise<unknown>
  loadExtensions?: () => Promise<unknown>
  registry?: RouteRegistry
}

/**
 * The boot sequence of the SPA (`app.tsx`): wait for i18n and every
 * extension bundle, then build the router, so the auth page overrides and
 * the routes the bundles registered are in place (v2.2.0; before, the
 * router was built when `@/router` was imported, ahead of every bundle,
 * and an `auth:login` override never rendered).
 *
 * A failure of either never blocks the panel: the router is built with
 * whatever loaded. A user the panel gate refuses gets no bundle and no
 * router (`null`): `app.tsx` renders the forbidden screen instead.
 */
export async function bootAppRouter(panelForbidden: boolean, deps: AppBootDependencies = {}): Promise<AppRouter | null> {
  const i18n = deps.initI18n ?? initI18n
  const loadExtensions = deps.loadExtensions ?? loadConsumerExtensions

  await Promise.all([
    i18n().catch(() => undefined),
    panelForbidden ? Promise.resolve() : loadExtensions().catch(() => undefined),
  ])

  return panelForbidden ? null : createAppRouter(deps.registry ?? routeRegistry)
}
