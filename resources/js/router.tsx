import { ComponentType, createElement } from 'react'
import { createBrowserRouter, type RouteObject } from 'react-router'
import { Layout } from '@/components/Layout'
import { ResourceLayout } from '@/components/ResourceLayout'
import { LoginPage } from '@/pages/Login'
import { RegisterPage } from '@/pages/Register'
import { ForgotPasswordPage } from '@/pages/ForgotPassword'
import { ResetPasswordPage } from '@/pages/ResetPassword'
import { EmailVerifyNoticePage } from '@/pages/EmailVerifyNotice'
import { InvitationAcceptPage } from '@/pages/InvitationAccept'
import { MagicLinkConfirmPage } from '@/pages/MagicLinkConfirm'
import { PasswordChangeRequiredPage } from '@/pages/PasswordChangeRequired'
import { DashboardPage } from '@/pages/Dashboard'
import { NotFoundPage } from '@/pages/NotFound'
import { ForbiddenPage } from '@/pages/Forbidden'
import { ServerErrorPage } from '@/pages/ServerError'
import { TwoFactorChallengePage } from '@/pages/TwoFactorChallenge'
import { RegisteredRoutePage } from '@/pages/RegisteredRoutePage'
import { BASE_PATH, config } from '@/lib/config'
import { componentRegistry } from '@/lib/componentRegistry'
import { crumbLabelFor, inRouterOrder, routeRegistry, type RegisteredRoute, type RouteRegistry } from '@/lib/routeRegistry'

/**
 * Resolve an auth page component by registry key, falling back to the
 * bundled default when no consumer override is registered.
 *
 * Mirrors `Layout.tsx:resolveShellComponent`. Registered overrides come
 * from `php artisan martis:component MyLogin --type=login-page` (and
 * the matching --type values for register, forgot-password,
 * reset-password, email-verify-notice, and password-change, whose key is
 * `auth:password-change`). `auth:invitation-accept`
 * has no dedicated `--type` scaffold yet; a consumer overrides it the
 * same way, by calling `componentRegistry.register('auth:invitation-accept', MyScreen)`
 * directly; the registry key works for any string, scaffold or not.
 *
 * Called by `buildAppRoutes()`, so the keys are read when the router is
 * built, after the extension bundles have loaded (v2.2.0). Before, the
 * router was built when this module loaded and the bundled pages always
 * rendered.
 */
function resolveAuthPage<P>(key: string, fallback: ComponentType<P>): ComponentType<P> {
  if (componentRegistry.has(key)) {
    const override = componentRegistry.resolve(key)
    if (override) return override as unknown as ComponentType<P>
  }

  return fallback
}

/**
 * The routes applications registered on `routeRegistry` (v2.2.0+), as
 * children of the shell route, in `inRouterOrder()` so a ranking tie never
 * follows the order the extension bundles loaded in. Each renders its page
 * through `RegisteredRoutePage`, and its handle carries the crumb label the
 * breadcrumb shows as given.
 */
function registeredRouteObjects(registered: readonly RegisteredRoute[]): RouteObject[] {
  return inRouterOrder(registered).map((route) => ({
    path: route.path,
    element: <RegisteredRoutePage route={route} />,
    handle: { crumbLabel: crumbLabelFor(route) },
  }))
}

/**
 * The whole route tree of the SPA. The registered routes sit after every
 * package route and before the catch-all: the registry refuses a path
 * whose first segment Martis uses, so they can only take paths that would
 * otherwise render the 404 page.
 */
export function buildAppRoutes(registered: readonly RegisteredRoute[]): RouteObject[] {
  const Login = resolveAuthPage('auth:login', LoginPage)
  const Register = resolveAuthPage('auth:register', RegisterPage)
  const ForgotPassword = resolveAuthPage('auth:forgot-password', ForgotPasswordPage)
  const ResetPassword = resolveAuthPage('auth:reset-password', ResetPasswordPage)
  const EmailVerifyNotice = resolveAuthPage('auth:email-verify-notice', EmailVerifyNoticePage)
  const InvitationAccept = resolveAuthPage('auth:invitation-accept', InvitationAcceptPage)
  const PasswordChange = resolveAuthPage('auth:password-change', PasswordChangeRequiredPage)

  return [
    {
      path: '/login',
      element: createElement(Login),
    },
    {
      path: '/register',
      element: createElement(Register),
    },
    {
      path: '/forgot-password',
      element: createElement(ForgotPassword),
    },
    {
      path: '/reset-password/:token',
      element: createElement(ResetPassword),
    },
    {
      path: '/email/verify',
      element: createElement(EmailVerifyNotice),
    },
    {
      path: '/password/change',
      element: createElement(PasswordChange),
    },
    {
      path: '/invitations/accept/:token',
      element: createElement(InvitationAccept),
    },
    {
      // The page the emailed sign-in link opens: it asks to confirm, and
      // only its POST signs in (v2.4.0).
      path: '/magic-link/confirm',
      element: <MagicLinkConfirmPage />,
    },
    {
      path: '/2fa/challenge',
      element: <TwoFactorChallengePage />,
    },
    {
      path: '/',
      element: <Layout />,
      children: [
        {
          index: true,
          element: <DashboardPage />,
          handle: { crumb: 'dashboard' },
        },
        {
          // Direct deep-link to a registered Dashboard by its uriKey.
          // Powers `MenuItem::dashboard($class)` URLs like
          // `/dashboards/client-insights`. The DashboardPage reads the
          // uriKey via useParams and renders that dashboard's cards;
          // omitting the segment falls back to the index route above.
          path: 'dashboards/:uriKey',
          element: <DashboardPage />,
          handle: { crumb: 'dashboard' },
        },
        {
          path: 'profile',
          lazy: async () => {
            const { ProfilePage } = await import('@/pages/Profile')
            return { element: <ProfilePage />, handle: { crumb: 'profile' } }
          },
        },
        {
          path: 'system/cache',
          lazy: async () => {
            const { CacheAdminPage } = await import('@/pages/CacheAdmin')
            return { element: <CacheAdminPage />, handle: { crumb: 'system_cache' } }
          },
        },
        // Developer-only Component Inspector: pick any registered
        // component, feed it a JSON payload, see it render in
        // isolation. Gated on `config.dev.toolsEnabled` (PHP side:
        // `MARTIS_DEV_TOOLS`, default true in `local`/`testing`, false
        // elsewhere). When the flag is off the route is not registered
        // at all so production bundles never resolve it.
        ...(config.dev?.toolsEnabled
          ? [
              {
                path: 'dev/components',
                lazy: async () => {
                  const { ComponentInspectorPage } = await import('@/pages/ComponentInspector')
                  return { element: <ComponentInspectorPage />, handle: { crumb: 'dev_components' } }
                },
              },
            ]
          : []),
        {
          path: 'tools/:uriKey',
          lazy: async () => {
            const { ToolPage } = await import('@/pages/ToolPage')
            return { element: <ToolPage />, handle: { crumb: 'tool' } }
          },
        },
        {
          // Pathless: wraps every resource page in the layout registered
          // for its resource on `layoutRegistry` (docs/overrides.md,
          // "Layout Overrides"). The child paths and crumbs are unchanged.
          element: <ResourceLayout />,
          children: [
            {
              path: 'resources/:resource',
              lazy: async () => {
                const { ResourceIndexPage } = await import('@/pages/ResourceIndex')
                return { element: <ResourceIndexPage />, handle: { crumb: 'resources' } }
              },
            },
            {
              path: 'resources/:resource/lens/:lens',
              lazy: async () => {
                const { ResourceLensPage } = await import('@/pages/ResourceLens')
                return { element: <ResourceLensPage />, handle: { crumb: 'lens' } }
              },
            },
            {
              path: 'resources/:resource/create',
              lazy: async () => {
                const { ResourceCreatePage } = await import('@/pages/ResourceCreate')
                return { element: <ResourceCreatePage />, handle: { crumb: 'create' } }
              },
            },
            {
              path: 'resources/:resource/:id',
              lazy: async () => {
                const { ResourceDetailPage } = await import('@/pages/ResourceDetail')
                return { element: <ResourceDetailPage />, handle: { crumb: 'detail' } }
              },
            },
            {
              path: 'resources/:resource/:id/edit',
              lazy: async () => {
                const { ResourceUpdatePage } = await import('@/pages/ResourceUpdate')
                return { element: <ResourceUpdatePage />, handle: { crumb: 'edit' } }
              },
            },
          ],
        },
        {
          path: '403',
          element: <ForbiddenPage />,
          handle: { crumb: 'error_forbidden' },
        },
        {
          path: '500',
          element: <ServerErrorPage />,
          handle: { crumb: 'error_server_error' },
        },
        ...registeredRouteObjects(registered),
        {
          path: '*',
          element: <NotFoundPage />,
          handle: { crumb: 'error_not_found' },
        },
      ],
    },
  ]
}

/**
 * Build the SPA's router. `app.tsx` calls it once every extension bundle
 * has loaded, so the auth page overrides and the registered routes those
 * bundles added are in place. It seals the registry: a route registered
 * later could never be added to a router that already exists.
 */
export function createAppRouter(registry: RouteRegistry = routeRegistry) {
  registry.seal()

  return createBrowserRouter(buildAppRoutes(registry.routes()), { basename: BASE_PATH })
}

export type AppRouter = ReturnType<typeof createAppRouter>
