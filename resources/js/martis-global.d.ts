/**
 * The `window.Martis` global the SPA fills at boot for consumer extension
 * bundles (`app.tsx`, and `lib/keyboardShortcuts.ts` for `shortcuts`).
 *
 * It is declared in this ambient file, which no module imports, instead of
 * next to the code that fills it: `npm run build:types` bundles the
 * declarations of every module `@martis/runtime` serves into the consumer's
 * `.shims/runtime.d.mts`, and a `Window.Martis` declaration there would clash
 * (TS2717) with the one in the consumer's
 * `resources/js/martis-extensions/index.ts` as soon as that file imports the
 * runtime.
 */
interface Window {
  Martis?: {
    /** The keyboard-shortcut registry (also `addShortcut`, `disableShortcut` and `listShortcuts` on `@martis/runtime`). */
    shortcuts?: {
      add: typeof import('@/lib/keyboardShortcuts').addShortcut
      remove: typeof import('@/lib/keyboardShortcuts').disableShortcut
      list: typeof import('@/lib/keyboardShortcuts').listShortcuts
    }
    /**
     * Component registry exposed by `app.tsx` at boot so consumer
     * extension bundles can register Tools / overrides without
     * shipping their own copy of the registry. v1.8.19+.
     */
    componentRegistry?: unknown
    /**
     * React module instance bundled by the package. Consumer
     * extensions external `react` to this to share the JSX runtime
     * (no duplicate-instance hazards). v1.8.19+.
     */
    react?: unknown
    /**
     * React's `jsx-runtime` module exports (`jsx`, `jsxs`, `Fragment`)
     * the JSX transform compiles into. Mirrors `react?: unknown`
     * but for the separate `react/jsx-runtime` import surface that
     * consumer extension bundles need to resolve. v1.9.3+.
     */
    reactJsxRuntime?: unknown
    /**
     * `@martis/runtime` public surface, exposed by `app.tsx` at
     * boot. Consumer-extension shims re-export from here.
     * v1.10.0+. See `lib/martisRuntime.ts`.
     */
    runtime?: unknown
    /** Package version (semver string). v1.8.19+. */
    version?: string
  } & Record<string, unknown>
}
