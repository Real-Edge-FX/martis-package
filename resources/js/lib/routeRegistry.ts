import { isValidElement, type ComponentType } from 'react'
import type { ToolDescriptor } from '@/types'

/**
 * Route Registry (v2.2.0+, docs/custom-pages.md).
 *
 * Lets an application give its own pages URLs below the Martis base path
 * (`/findings`, `/findings/:findingId`), rendered inside the shell.
 * `createAppRouter()` (router.tsx) reads the accepted routes once, after
 * every extension bundle has loaded, and seals the registry.
 *
 * Usage, from a consumer extension, at the top level of the bundle:
 *   import { routeRegistry } from '@martis/runtime'
 *   routeRegistry.register({ path: 'findings/:findingId', component: FindingPage, crumb: 'Findings', tool: 'findings' })
 *
 * A refused registration logs `[martis] routeRegistry: <reason>` and
 * returns false. Nothing throws: the bundle runs as one module, and an
 * exception would lose every registration after it (Tools, cards,
 * overrides).
 */

/**
 * First path segments Martis keeps for itself, compared case-insensitively
 * (React Router matches case-insensitively): the pages of the shell, the
 * sign-in and account pages, and the server routes a reload never hands to
 * the SPA. A registered path must start with another static segment, so it
 * can never match a path Martis owns, whatever the route ranking.
 */
export const RESERVED_ROUTE_SEGMENTS: readonly string[] = Object.freeze([
  // Pages of the shell
  'dashboards', 'profile', 'system', 'dev', 'tools', 'resources', '403', '500',
  // Sign-in and account pages
  'login', 'register', 'forgot-password', 'reset-password', 'email', 'invitations', 'magic-link', '2fa', 'password',
  // Server routes that never reach the SPA: `api-docs` is the default
  // `martis.api_docs.path` (the API documentation, when enabled)
  'api', 'api-docs', 'sso', 'logout', 'favicon.ico',
])

/** The props a registered page receives: the Tool it is bound to, once resolved. */
export interface RegisteredRoutePageProps {
  tool?: ToolDescriptor
}

/** What `routeRegistry.register()` takes. */
export interface RouteRegistration {
  /** The path below the Martis base path, e.g. 'findings/:findingId'. Leading and trailing slashes are ignored. */
  path: string
  /** A componentRegistry key, resolved when the page renders, or the component itself. */
  component: string | ComponentType<RegisteredRoutePageProps>
  /** The breadcrumb label, shown as given (never passed through t()). */
  crumb?: string
  /** The uriKey of the Tool whose visibility and soft lock guard the page. */
  tool?: string
}

/** A registration the registry accepted, with its path normalised. */
export interface RegisteredRoute {
  path: string
  component: string | ComponentType<RegisteredRoutePageProps>
  crumb: string | null
  tool: string | null
}

const STATIC_SEGMENT = /^[A-Za-z0-9._~-]+$/
const PARAM_SEGMENT = /^:[A-Za-z_][A-Za-z0-9_-]*$/

const SEALED_REASON =
  'routes must be registered when the extension bundle loads (at its top level): the router was already built'

function normalisePath(path: string): string {
  return path.replace(/^\/+/, '').replace(/\/+$/, '')
}

function isStatic(segment: string): boolean {
  return STATIC_SEGMENT.test(segment) && segment !== '.' && segment !== '..'
}

/** Why a normalised, non-empty path is refused, or null when it is valid. */
function pathProblem(path: string): string | null {
  const segments = path.split('/')

  for (let i = 0; i < segments.length; i++) {
    const segment = segments[i]!
    if (segment === '*' && i === segments.length - 1) continue
    if (isStatic(segment) || PARAM_SEGMENT.test(segment)) continue
    return `"${segment}" is not a valid segment of "${path}": use static text (letters, digits, ".", "_", "~", "-"), a :parameter, or "*" as the last segment`
  }

  const first = segments[0]!
  if (!isStatic(first)) return `the first segment of "${path}" must be static text, not a parameter or "*"`
  if (RESERVED_ROUTE_SEGMENTS.includes(first.toLowerCase())) {
    return `"${first}" is reserved by Martis: start "${path}" with another segment`
  }

  return null
}

/** Two paths are one route when their shapes are equal: parameters unnamed, static text lower-cased. */
function shapeOf(path: string): string {
  return path
    .split('/')
    .map((segment) => (segment.startsWith(':') ? ':' : segment.toLowerCase()))
    .join('/')
}

/**
 * The order the router receives the registered routes in: by path, letter
 * case aside, never by registration order. React Router gives a tie in its
 * ranking (`a/:x/c` and `a/b/:y` on `/a/b/c`) to the earlier sibling, and
 * routes from two bundles are registered in the order the network delivers
 * the bundles.
 */
export function inRouterOrder(routes: readonly RegisteredRoute[]): RegisteredRoute[] {
  const compare = (a: string, b: string): number => (a < b ? -1 : a > b ? 1 : 0)

  return [...routes].sort((a, b) => compare(a.path.toLowerCase(), b.path.toLowerCase()) || compare(a.path, b.path))
}

function isComponent(value: unknown): boolean {
  if (typeof value === 'string') return value.trim() !== ''
  if (typeof value === 'function') return true
  // memo / forwardRef / lazy components are objects tagged by React.
  return typeof value === 'object' && value !== null && '$$typeof' in value
}

/** The trimmed text, null when absent, false when present but empty or not a string. */
function optionalText(value: unknown): string | null | false {
  if (value === undefined) return null
  if (typeof value !== 'string' || value.trim() === '') return false
  return value.trim()
}

// Exported so the runtime's generated declarations (`npm run build:types`)
// can name the type of `routeRegistry`.
export class RouteRegistry {
  private readonly accepted: RegisteredRoute[] = []
  /** Every shape ever registered, the ones dropped as duplicates included. */
  private readonly shapes = new Set<string>()
  private sealed = false

  /**
   * Register a page at a path below the Martis base path. Returns true when
   * the route is accepted; a refused route logs the reason and returns false.
   */
  register(route: RouteRegistration): boolean {
    const refuse = (reason: string): false => {
      console.error(`[martis] routeRegistry: ${reason}`, route)
      return false
    }

    if (this.sealed) return refuse(SEALED_REASON)
    if (typeof route !== 'object' || route === null) return refuse('a route is an object with a path and a component')
    if (typeof route.path !== 'string') return refuse('the path must be a string')

    const path = normalisePath(route.path)
    if (path === '') return refuse('the path must not be empty')

    const problem = pathProblem(path)
    if (problem !== null) return refuse(problem)

    if (isValidElement(route.component)) {
      return refuse('the component is an element (<Page />): pass the component itself (Page)')
    }
    if (!isComponent(route.component)) {
      return refuse('the component must be a componentRegistry key or a React component')
    }
    const component = typeof route.component === 'string' ? route.component.trim() : route.component

    const tool = optionalText(route.tool)
    if (tool === false) return refuse('tool must be the uriKey of a Tool')

    const crumb = optionalText(route.crumb)
    if (crumb === false) return refuse('crumb must be a non-empty string')

    // Extension bundles load in parallel, so which of two registrations of
    // one shape comes first depends on the network. Keeping either would
    // make the page that renders depend on it too: both are dropped, and
    // the path renders the 404 page on every load until one is removed.
    const shape = shapeOf(path)
    if (this.shapes.has(shape)) {
      const index = this.accepted.findIndex((accepted) => shapeOf(accepted.path) === shape)
      if (index !== -1) this.accepted.splice(index, 1)
      return refuse(`a route of the shape "${path}" is already registered: both are refused, so the page never depends on which extension bundle loaded first`)
    }

    this.shapes.add(shape)
    this.accepted.push({ path, component, crumb, tool })

    return true
  }

  /** Whether a route of this path's shape is registered (and not dropped as a duplicate). */
  has(path: string): boolean {
    const shape = shapeOf(normalisePath(path))
    return this.accepted.some((accepted) => shapeOf(accepted.path) === shape)
  }

  /** The accepted routes, in registration order. */
  routes(): readonly RegisteredRoute[] {
    return [...this.accepted]
  }

  /**
   * Refuse every later registration.
   *
   * @internal Called by `createAppRouter()`, which reads the routes once.
   */
  seal(): void {
    this.sealed = true
  }
}

export const routeRegistry = new RouteRegistry()

/**
 * The breadcrumb label of a registered route: its `crumb`, else its first
 * segment with dashes and underscores as spaces and the first letter
 * upper-cased (`project-reports` gives `Project reports`).
 */
export function crumbLabelFor(route: RegisteredRoute): string {
  if (route.crumb !== null) return route.crumb

  const words = (route.path.split('/')[0] ?? '').replace(/[-_]+/g, ' ').trim()

  return words.charAt(0).toUpperCase() + words.slice(1)
}
