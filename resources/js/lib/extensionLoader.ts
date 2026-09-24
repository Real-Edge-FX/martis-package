/**
 * Consumer extension loader.
 *
 * Loads every extension bundle listed in `window.MartisConfig.extensions`
 * (sourced from the `MARTIS_EXTENSIONS` env, comma-separated). `app.tsx`
 * awaits it before mounting React.
 *
 * v1.8.19 shipped this as fire-and-forget: imports started, React
 * mounted, the SPA raced the network. On a cold-cache navigation
 * straight to `/martis/tools/{key}` the ToolPage queried the registry
 * before the bundle had registered the component, the placeholder
 * fired, and a subsequent registry write never re-rendered the page —
 * so the user saw "No React component is registered…" forever.
 *
 * v1.9.2 awaits every import (with a 5s per-URL timeout safety net so
 * a hung extension cannot keep the whole panel hidden forever) before
 * mounting React. Failures stay isolated — one broken bundle logs and
 * the rest still load — and the slowest case is a single round-trip
 * for the cached extensions.js, which is well under the i18n init
 * cost we already wait on.
 *
 * The timeout is cleared as soon as its import settles, so the
 * "exceeded" warning only appears for a bundle that really did not
 * load in time.
 */

export const EXTENSION_LOAD_TIMEOUT_MS = 5_000

/** Imports one extension bundle; `import()` in the browser. */
export type ExtensionImporter = (url: string) => Promise<unknown>

const importExtension: ExtensionImporter = (url) => import(/* @vite-ignore */ url)

export async function loadConsumerExtensions(
  urls: readonly unknown[] = window.MartisConfig?.extensions ?? [],
  importer: ExtensionImporter = importExtension,
  timeoutMs: number = EXTENSION_LOAD_TIMEOUT_MS,
): Promise<void> {
  await Promise.all(
    urls
      .filter((url): url is string => typeof url === 'string' && url !== '')
      .map((url) => {
        let timer: ReturnType<typeof setTimeout> | undefined

        const timeout = new Promise<void>((resolve) => {
          timer = setTimeout(() => {
            console.warn('[martis] extension load exceeded', timeoutMs, 'ms; mounting without it', url)
            resolve()
          }, timeoutMs)
        })

        const load = importer(url)
          .then(() => undefined)
          .catch((err: unknown) => {
            console.error('[martis] failed to load extension', url, err)
          })
          .finally(() => clearTimeout(timer))

        return Promise.race([load, timeout])
      }),
  )
}
