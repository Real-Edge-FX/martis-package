import { Suspense, createElement, lazy, useState, type ComponentType, type ReactNode } from 'react'
import type { FieldDisplayProps, FieldInputProps } from '@/components/fields/types'
import { ErrorBoundary } from '@/components/ErrorBoundary'
import { MartisLoader, DefaultMartisLoader } from '@/components/Loader'
import { reloadOnStaleChunk } from '@/lib/staleChunk'

/**
 * What a lazy loader resolves to: an ES module with a default export (the
 * `import('./Page')` shape), or the component itself.
 */
export type RegistryModule<P> = { default: ComponentType<P> } | ComponentType<P>

/** A function that loads a component on demand, usually `() => import('./Page')`. */
export type RegistryLoader<P> = () => Promise<RegistryModule<P>>

/** What a registration takes: the component, or a loader for it. */
export type RegistryComponent<P> = ComponentType<P> | RegistryLoader<P>

/** Options of a registration. They only matter for a lazy one. */
export interface RegistryOptions {
  /** Shown while the chunk loads: a node, or a component. Default: the Martis loader. */
  fallback?: ReactNode | ComponentType
  /**
   * When to fetch the chunk before it is rendered. `'idle'` fetches it when the
   * browser is idle after the app mounted. `'never'` (default) fetches it on
   * first render. `'visible'` is accepted and currently behaves as `'never'`:
   * the chunk loads when the page that renders it mounts, which is the moment
   * it becomes visible.
   */
  preload?: 'idle' | 'visible' | 'never'
}

/**
 * Whether the function handed to `register()` is a loader rather than a
 * component. A loader and a zero-argument function component look the same to
 * the language (`() => import('./X')` and `() => <X />` are both arrow
 * functions of length 0), so calling it to find out is not an option (a
 * component must not run outside a render). Reading its source is: a loader
 * is an expression-bodied arrow (or a `return import(...)` function) whose
 * body reaches an `import(` call before any block. That also covers the
 * `__vitePreload(() => import(...))` and minified wrappers Vite emits. A
 * component that matched would need to be a zero-argument arrow that starts
 * with an `import()` expression, which is not a component. Class components,
 * memo/forwardRef objects and any function that takes props never qualify.
 * `registerLazy()` skips the detection and is what the docs recommend.
 */
function isLoader(value: unknown): value is RegistryLoader<never> {
  if (typeof value !== 'function') return false

  const fn = value as { length: number; prototype?: { isReactComponent?: unknown }; $$typeof?: unknown }

  if (fn.length !== 0 || fn.$$typeof !== undefined || fn.prototype?.isReactComponent !== undefined) return false

  const source = Function.prototype.toString.call(value)

  return (
    /^(?:async\s*)?\(\s*\)\s*=>\s*[^{;]*?\bimport\s*\(/.test(source) ||
    /^(?:async\s+)?function\s*[\w$]*\s*\(\s*\)\s*\{\s*return\s+[^;{]*?\bimport\s*\(/.test(source)
  )
}

/** Per-registration state of a lazy component. */
interface LazyEntry {
  /** Fetches the chunk once; later calls share the promise. */
  preload: () => Promise<void>
  options: RegistryOptions
}

/**
 * Component Registry — Bloco 9 (Override System v1)
 *
 * Three-tier resolution for field components:
 *
 * 1. Explicit component key (from field schema `component` property)
 *    → `registry.register('status-badge', StatusBadge)`
 *    → Field: Text::make('status')->component('status-badge')
 *
 * 2. Per-resource override (resource + field name)
 *    → `componentRegistry.registerResourceFieldDisplay('posts', 'status', StatusBadge)`
 *
 * 3. Global type override (all fields of a type)
 *    → `componentRegistry.registerFieldDisplay('text', MyTextField)`
 *
 * 4. Built-in default (registered via registerDefaultFields())
 *
 * Resolution order: explicit key → resource-field → type → built-in default
 *
 * Any registration can be lazy (v2.20.0): `registerLazy(key, () => import('./X'),
 * { fallback, preload })`, or `register(key, () => import('./X'))`, which is
 * detected from the loader's source (see `isLoader`). `resolve()` then returns
 * a component with its own Suspense boundary, so it renders anywhere a registry
 * component does.
 *
 * Usage (a consumer extension imports it from `@martis/runtime`):
 *   import { componentRegistry } from '@/lib/componentRegistry'
 *
 *   // Register custom display for all "text" fields globally
 *   componentRegistry.registerFieldDisplay('text', MyCustomTextField)
 *
 *   // Register custom display for "status" field on "posts" resource only
 *   componentRegistry.registerResourceFieldDisplay('posts', 'status', StatusBadgeDisplay)
 *
 *   // Register by explicit key (matching field.component in PHP)
 *   componentRegistry.register('status-badge', StatusBadgeDisplay)
 *
 * Exported so the runtime's generated declarations (`npm run build:types`)
 * can name the type of `componentRegistry`.
 */
export class ComponentRegistry {
  /** Keyed by arbitrary string (explicit component keys, type keys, resource-field keys). */
  private readonly components = new Map<string, ComponentType<never>>()

  /** Lazy registrations by key (a subset of `components`, which holds their `React.lazy` wrapper). */
  private readonly lazyEntries = new Map<string, LazyEntry>()

  /** True once the app mounted: idle preloads registered later start at once. */
  private idleStarted = false

  // -------------------------------------------------------------------------
  // Low-level registration
  // -------------------------------------------------------------------------

  /**
   * Register a component under an arbitrary key.
   * Also used for explicit `field.component` keys from PHP.
   */
  register<P = never>(key: string, component: RegistryComponent<P>, options: RegistryOptions = {}): void {
    if (isLoader(component)) {
      this.registerLazy(key, component as unknown as RegistryLoader<P>, options)
      return
    }

    this.lazyEntries.delete(key)
    this.components.set(key, component as ComponentType<never>)
  }

  /**
   * Register a component that loads on demand. The loader runs on the first
   * render (or on `preload()`), at most once, and its chunk is fetched then.
   * Renders suspend until it resolves, which every Martis call site that
   * renders a registry component handles. Prefer this to passing a loader to
   * `register()`: it never has to guess whether a function is a loader.
   *
   *   componentRegistry.registerLazy('tool:reports', () => import('./tools/Reports'))
   */
  registerLazy<P = never>(key: string, loader: RegistryLoader<P>, options: RegistryOptions = {}): void {
    let promise: Promise<{ default: ComponentType<P> }> | undefined

    const load = (): Promise<{ default: ComponentType<P> }> => {
      promise ??= loader().then(
        (module) => ({ default: typeof module === 'function' ? module : module.default }),
        (error: unknown) => {
          // A failed fetch is not cached, so preload() and a retried Lazy call the loader again.
          promise = undefined
          throw error
        },
      )

      return promise
    }

    // A stale chunk (a tab opened before a deploy) reloads the page; see
    // `reloadOnStaleChunk`. The preload path below does not reload: a
    // background fetch must not take the page away from the operator.
    //
    // `React.lazy` keeps a rejected promise for good, so a failed `Lazy` can
    // never retry. `Lazy` is therefore replaced by a fresh one when the error
    // boundary's "Try again" resets (only if the current one failed, so a
    // loaded component keeps its identity), and `load()` calls the loader again.
    let failed = false
    const makeLazy = () =>
      lazy(() =>
        load().then(
          (module) => module,
          (error: unknown) => {
            failed = true
            return reloadOnStaleChunk<{ default: ComponentType<P> }>(error)
          },
        ),
      )
    let Lazy = makeLazy()

    // What `resolve()` returns. It carries its own Suspense boundary, so
    // every place that renders a registry component (tool pages, cards,
    // filters, overrides, field displays and inputs, the shell slots) works
    // with a lazy one without knowing it is lazy, and the component keeps a
    // stable identity across renders. The fallback is the registered one, else
    // the Martis loader; the bundled loader for the `loader` key itself, which
    // would otherwise suspend on its own fallback. A chunk that cannot load
    // (after the stale-chunk reload, or while offline) lands in the error
    // boundary, which shows the Martis error state in place of this component
    // only, with a "Try again" that fetches the chunk again.
    const Guard = ErrorBoundary as unknown as ComponentType<{ label: string; onReset: () => void; children?: ReactNode }>
    const Wrapper = (props: object) => {
      const [, setAttempt] = useState(0)
      const retry = () => {
        if (failed) {
          failed = false
          Lazy = makeLazy()
        }
        setAttempt((n) => n + 1)
      }

      return createElement(
        Guard,
        { label: key, onReset: retry },
        createElement(
          Suspense,
          { fallback: this.fallbackNode(key === 'loader' ? { ...options, fallback: options.fallback ?? createElement(DefaultMartisLoader) } : options) },
          createElement(Lazy as unknown as ComponentType<object>, props),
        ),
      )
    }
    Wrapper.displayName = `Lazy(${key})`

    this.components.set(key, Wrapper as unknown as ComponentType<never>)
    this.lazyEntries.set(key, { preload: () => load().then(() => undefined), options })

    if (options.preload === 'idle' && this.idleStarted) this.schedulePreload(key)
  }

  /** True when the key was registered with a loader. */
  isLazy(key: string): boolean {
    return this.lazyEntries.has(key)
  }

  /**
   * Fetch a lazy component's chunk ahead of its first render. Idempotent: the
   * loader runs once. Resolves at once for an eager or unknown key and
   * rejects when the fetch fails.
   */
  preload(key: string): Promise<void> {
    return this.lazyEntries.get(key)?.preload() ?? Promise.resolve()
  }

  /**
   * Start the `preload: 'idle'` registrations when the browser is idle. The
   * SPA calls this once after it mounted; keys registered afterwards start
   * the same way. Failures are ignored here (the render retries and reports).
   */
  runIdlePreloads(): void {
    this.idleStarted = true

    for (const [key, entry] of this.lazyEntries) {
      if (entry.options.preload === 'idle') this.schedulePreload(key)
    }
  }

  private schedulePreload(key: string): void {
    const run = () => {
      this.preload(key).catch(() => undefined)
    }

    if (typeof window.requestIdleCallback === 'function') {
      window.requestIdleCallback(run)
    } else {
      window.setTimeout(run, 200)
    }
  }

  private fallbackNode(options: RegistryOptions): ReactNode {
    const { fallback } = options

    if (fallback === undefined) return createElement(MartisLoader)

    return typeof fallback === 'function' ? createElement(fallback as ComponentType) : fallback
  }

  // -------------------------------------------------------------------------
  // Typed registration helpers
  // -------------------------------------------------------------------------

  /** Override the display component for all fields of a given type (e.g. "text"). */
  registerFieldDisplay(
    type: string,
    component: RegistryComponent<FieldDisplayProps>,
    options?: RegistryOptions,
  ): void {
    this.register(`field:display:${type}`, component, options)
  }

  /** Override the input component for all fields of a given type (e.g. "text"). */
  registerFieldInput(type: string, component: RegistryComponent<FieldInputProps>, options?: RegistryOptions): void {
    this.register(`field:input:${type}`, component, options)
  }

  /**
   * Override the display component for a specific field on a specific resource.
   *
   * @param resourceKey  The resource URI key (e.g. "posts", "users")
   * @param fieldName    The field attribute name (e.g. "status", "name")
   * @param component    The React component to use for display
   */
  registerResourceFieldDisplay(
    resourceKey: string,
    fieldName: string,
    component: RegistryComponent<FieldDisplayProps>,
    options?: RegistryOptions,
  ): void {
    this.register(`field:display:${resourceKey}:${fieldName}`, component, options)
  }

  /**
   * Override the input component for a specific field on a specific resource.
   *
   * @param resourceKey  The resource URI key (e.g. "posts", "users")
   * @param fieldName    The field attribute name (e.g. "status", "name")
   * @param component    The React component to use for the form input
   */
  registerResourceFieldInput(
    resourceKey: string,
    fieldName: string,
    component: RegistryComponent<FieldInputProps>,
    options?: RegistryOptions,
  ): void {
    this.register(`field:input:${resourceKey}:${fieldName}`, component, options)
  }

  // -------------------------------------------------------------------------
  // Resolution
  // -------------------------------------------------------------------------

  /** Resolve a display component with full 4-tier fallback chain. */
  resolveDisplay(
    type: string,
    fieldName: string,
    resourceKey: string | undefined,
    explicitKey: string | null | undefined,
    fallback: ComponentType<FieldDisplayProps>,
  ): ComponentType<FieldDisplayProps> {
    // Tier 1: explicit component key from field schema
    if (explicitKey) {
      const explicit = this.components.get(explicitKey) as ComponentType<FieldDisplayProps> | undefined
      if (explicit) return explicit
    }

    // Tier 2: per-resource override
    if (resourceKey) {
      const resourceSpecific = this.components.get(
        `field:display:${resourceKey}:${fieldName}`,
      ) as ComponentType<FieldDisplayProps> | undefined
      if (resourceSpecific) return resourceSpecific
    }

    // Tier 3: global type override
    const typeLevel = this.components.get(`field:display:${type}`) as ComponentType<FieldDisplayProps> | undefined
    if (typeLevel) return typeLevel

    // Tier 4: built-in default
    return fallback
  }

  /** Resolve an input component with full 4-tier fallback chain. */
  resolveInput(
    type: string,
    fieldName: string,
    resourceKey: string | undefined,
    explicitKey: string | null | undefined,
    fallback: ComponentType<FieldInputProps>,
  ): ComponentType<FieldInputProps> {
    // Tier 1: explicit component key from field schema
    if (explicitKey) {
      const explicit = this.components.get(explicitKey) as ComponentType<FieldInputProps> | undefined
      if (explicit) return explicit
    }

    // Tier 2: per-resource override
    if (resourceKey) {
      const resourceSpecific = this.components.get(
        `field:input:${resourceKey}:${fieldName}`,
      ) as ComponentType<FieldInputProps> | undefined
      if (resourceSpecific) return resourceSpecific
    }

    // Tier 3: global type override
    const typeLevel = this.components.get(`field:input:${type}`) as ComponentType<FieldInputProps> | undefined
    if (typeLevel) return typeLevel

    // Tier 4: built-in default
    return fallback
  }

  /** Resolve a component by key, returning undefined if not found. */
  resolve(key: string): ComponentType<never> | undefined {
    return this.components.get(key)
  }

  /** Check whether a key is registered. */
  has(key: string): boolean {
    return this.components.has(key)
  }

  /**
   * Remove a single registration. Returns `true` when a registration
   * existed for the key, `false` otherwise. Mostly useful in tests
   * that mount a custom component for one assertion and want a clean
   * slate afterwards.
   */
  unregister(key: string): boolean {
    this.lazyEntries.delete(key)
    return this.components.delete(key)
  }

  /**
   * Drop every registration. Same intended use as `unregister()` —
   * keeping tests isolated. Production code should never call this;
   * the registry is built up at boot and is expected to be stable for
   * the lifetime of the SPA.
   */
  clear(): void {
    this.components.clear()
    this.lazyEntries.clear()
    this.idleStarted = false
  }

  /** List all registered keys (useful for debugging). */
  keys(): string[] {
    return Array.from(this.components.keys())
  }
}

export const componentRegistry = new ComponentRegistry()
