import { useEffect, useSyncExternalStore } from "react"
import type { MartisLoaderConfig } from "@/lib/config"

/**
 * Lightweight imperative store for the active resource's
 * `Resource::loaderConfig()` override. Resource pages call
 * `useResourceLoaderConfig(schema?.loaderConfig)` and the
 * `MartisLoader` wrapper in `@/components/Loader` reads from the
 * store via `useLoaderConfigOverride()`. The override is restored
 * on unmount, so navigating to a different page automatically falls
 * back to the global config.
 *
 * Implemented with `useSyncExternalStore` instead of React context so
 * no JSX surface change is required in any of the eight pages that
 * already render a `MartisLoader`. The store mirrors `useResourceAccent`
 * (which uses an analogous imperative pattern on `<html>` attributes).
 */

let current: Partial<MartisLoaderConfig> | null = null
const listeners = new Set<() => void>()

function subscribe(listener: () => void): () => void {
  listeners.add(listener)
  return () => {
    listeners.delete(listener)
  }
}

function emit(): void {
  for (const listener of listeners) listener()
}

function getSnapshot(): Partial<MartisLoaderConfig> | null {
  return current
}

/**
 * Hook for resource pages: push a per-resource loader override into
 * the store while the page is mounted; restore the previous value on
 * unmount. Empty / null overrides are a no-op (no push) so the global
 * config keeps applying.
 */
export function useResourceLoaderConfig(
  override: Partial<MartisLoaderConfig> | null | undefined,
): void {
  useEffect(() => {
    if (!override || (typeof override === "object" && Object.keys(override).length === 0)) {
      return
    }
    const previous = current
    current = override
    emit()
    return () => {
      current = previous
      emit()
    }
  }, [override])
}

/**
 * Hook consumed by the `MartisLoader` wrapper. Returns the active
 * override or `null` when no resource has pushed one.
 */
export function useLoaderConfigOverride(): Partial<MartisLoaderConfig> | null {
  return useSyncExternalStore(subscribe, getSnapshot, getSnapshot)
}
