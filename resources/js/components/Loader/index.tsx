import type { ComponentType } from "react"
import { componentRegistry } from "@/lib/componentRegistry"
import { useLoaderConfigOverride } from "@/contexts/LoaderConfigContext"
import { MartisLoader as DefaultMartisLoader, type MartisLoaderProps } from "./MartisLoader"

/**
 * Re-export the props type so consumers can type their own loaders.
 */
export type { MartisLoaderProps } from "./MartisLoader"

/**
 * Default export — registry-aware wrapper that also reads the
 * per-resource loader override from `LoaderConfigContext`.
 *
 * Resolves the `loader` key from `componentRegistry` on every render.
 * When a consumer registers a custom component (typically from
 * `boot.ts` via `componentRegistry.register('loader', MyLoader)`),
 * every page that imports `MartisLoader` from `@/components/Loader`
 * gets the swap automatically. Falls back to the bundled
 * `DefaultMartisLoader` when no override is registered.
 *
 * The lookup is intentionally per-render: the registry is a small Map
 * and the lookup is cheap. Doing it at module load would freeze the
 * resolution before consumer `boot.ts` had a chance to run.
 *
 * The wrapper also reads `LoaderConfigContext` so a resource page can
 * push `Resource::loaderConfig()` into the tree once and have every
 * loader inside that page inherit the override. Inline `configOverride`
 * passed at the call site wins over the context value for that one
 * call.
 */
export function MartisLoader(props: MartisLoaderProps): JSX.Element | null {
  const Override = componentRegistry.has("loader")
    ? (componentRegistry.resolve("loader") as ComponentType<MartisLoaderProps> | undefined)
    : undefined

  const Component = Override ?? DefaultMartisLoader

  // The context override is the per-resource baseline. An inline
  // `configOverride` prop wins so call sites stay flexible.
  const contextOverride = useLoaderConfigOverride()
  const merged = props.configOverride ?? contextOverride ?? undefined

  return <Component {...props} configOverride={merged ?? undefined} />
}

/**
 * Bundled default. Exposed for tests + for consumer overrides that want
 * to delegate to the original implementation for the unhandled prop
 * combos (e.g. wrap it with custom branding while still benefiting from
 * the config-driven message / icon / logo machinery).
 */
export { DefaultMartisLoader }
