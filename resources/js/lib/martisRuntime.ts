/**
 * `@martis/runtime` — public surface consumer extension bundles
 * import from when their components need package internals
 * (auth context, toast context, the API client, layout pieces, etc.).
 *
 * Why this barrel exists: prior to v1.10 the override stubs imported
 * directly from internal paths like `@/contexts/AuthContext`,
 * `@/lib/api`, `@/components/auth/AuthFrame`. Those paths only
 * resolved when the consumer's TSX got built INSIDE the package's
 * own vite tree (the legacy `MARTIS_USER_DIR` symlink mode that
 * v1.8.19 retired). When the build moved consumer-side, none of the
 * internal paths resolved any more, and the override stubs became
 * dead code.
 *
 * v1.10 introduces this barrel as the **public, versioned contract**
 * for everything an override stub needs. The package's `app.tsx`
 * imports each surface here, exposes the bag on
 * `window.Martis.runtime`, and the consumer's vite config (via
 * `martis:install`-published shims) aliases `@martis/runtime`,
 * `@martis/martis/*` and the legacy `@/contexts/*` / `@/lib/*` /
 * `@/components/auth/*` paths to the runtime shim, and `react-dom`,
 * `react-router-dom`, `react-i18next` and `@tanstack/react-query` to
 * shims of their own. The shims re-export from `window.Martis.runtime`,
 * so the published bundle reads the runtime off the host's React +
 * context tree at boot time.
 *
 * Adding to this surface: new exports are non-breaking (semver minor).
 * Removing or renaming = breaking (major). Rule of thumb: add only
 * what an override stub or a documented consumer example imports. Each
 * member also needs its `export const` line in
 * `stubs/extensions/runtime-shim.mjs.stub`, the file a consumer build
 * resolves `@martis/runtime` to (the shim test in `martisRuntime.test.tsx`
 * fails without it, and its docs guard fails on a docs example that
 * imports a name no shim exports or a path no alias resolves). It also
 * needs its re-export in `resources/js/extension-types/runtime.ts`, the
 * entry `npm run build:types` turns into the declarations published next
 * to the shim (`extensionTypes.test.ts` fails without it). The
 * consumer's vite also sends the legacy paths (`@/contexts/*`, `@/lib/*`,
 * `@/components/auth/*`, `@martis/martis/*`, `@/components/fields/types`)
 * to that shim, so they reach these names only, not package internals.
 *
 * @see docs/overrides.md (5.A) and docs/installation-guide.md
 *      ("Refreshing the extension scaffold after an upgrade")
 */

import * as ReactRouterDom from 'react-router-dom'
import * as ReactI18next from 'react-i18next'
import * as TanstackReactQuery from '@tanstack/react-query'
import { useAuth, AuthProvider, TwoFactorRequiredError, EmailVerificationRequiredError } from '@/contexts/AuthContext'
import { useToast, useToastSafe } from '@/contexts/ToastContext'
import { useIsMobile } from '@/hooks/useIsMobile'
import { api, ApiError } from '@/lib/api'
import { config } from '@/lib/config'
import { martisEventBus } from '@/lib/eventBus'
import { AuthFrame } from '@/components/auth/AuthFrame'
import { Sidebar } from '@/components/Sidebar'
import { Topbar } from '@/components/Topbar'
import { Footer } from '@/components/Footer'
import { FieldInput, FieldDisplay } from '@/components/fields/FieldRenderer'
import { NestedParentProvider } from '@/components/fields/NestedParentContext'
import { FieldsForm } from '@/components/fields/FieldsForm'
import { DrawerShell } from '@/components/overrides/DrawerShell'
import { Tooltip } from 'primereact/tooltip'
import { Dropdown } from 'primereact/dropdown'
import { MultiSelect } from 'primereact/multiselect'
import { createPortal, flushSync } from 'react-dom'
import { useMartisForm } from '@/hooks/useMartisForm'
import { useToolFields } from '@/hooks/useToolFields'
import { useRevalidateOnFocus } from '@/hooks/useRevalidateOnFocus'
import { componentRegistry } from '@/lib/componentRegistry'
import { iconRegistry } from '@/lib/iconRegistry'
import { layoutRegistry } from '@/lib/layoutRegistry'
import { usePageTitle } from '@/hooks/usePageTitle'
import { useModalHistoryLock } from '@/lib/historyLock'
import { OverridePropsProvider, useOverrideProps, useOverridePropsOptional } from '@/hooks/useOverrideProps'
import { useUnsavedChangesGuard } from '@/lib/useUnsavedChangesGuard'
import { useError } from '@/lib/useError'
import { cssVar, accentColor, mutedTextColor, chartPalette, resolveColor } from '@/lib/themeColors'
import { avatarColorForSeed } from '@/lib/avatarPalette'
import { Sparkline } from '@/components/metrics/Sparkline'
import { ClearButton } from '@/components/ClearButton'
import { MartisLoader } from '@/components/Loader'
import { usePreferences, usePreferencesOptional } from '@/contexts/PreferencesContext'
import { loadLocale, applyDocumentDirection } from '@/lib/i18n'
import { usePrefersReducedMotion } from '@/lib/usePrefersReducedMotion'
import { addShortcut, disableShortcut, listShortcuts } from '@/lib/keyboardShortcuts'

/**
 * The `@martis/runtime` bag. Exposed on `window.Martis.runtime`
 * by `app.tsx`. Consumer-extension shims re-export from here.
 */
export const martisRuntime = {
  // Hooks
  useAuth,
  useToast,
  useToastSafe,
  useIsMobile,

  // Auth context exceptions (thrown by useAuth().login() under specific conditions).
  TwoFactorRequiredError,
  EmailVerificationRequiredError,

  // Provider — consumer overrides that mount their own React tree need this.
  AuthProvider,

  // Lib
  api,
  ApiError,
  config,

  // Singleton pub/sub event bus (since v1.x). Lets a consumer's own
  // transport (ws-gateway, SSE, or an Echo listener they write) push
  // events — e.g. `martis:notification-received` — into native Martis
  // UI (the notification bell) instantly, without the package taking
  // an opinion on the transport. See docs/notifications.md ("Pluggable
  // real-time feed") and docs/components.md ("Event Bus").
  martisEventBus,

  // Components consumer overrides typically compose with.
  AuthFrame,
  Sidebar,
  Topbar,
  Footer,

  // Field renderer (since v1.14.0). Lets custom Action components,
  // Tools, and cards mount canonical Martis fields without
  // re-implementing behaviour. Pair these with the FieldDefinition
  // type re-exported below. See docs/overrides.md "Composing native
  // field components" for the two operational caveats: BelongsTo
  // outside a resource form needs related_resource on its
  // FieldDefinition, and consumer bundles hosted outside the Martis
  // shell must also load the published martis.css stylesheet.
  FieldInput,
  FieldDisplay,

  // Relation parent provider (since v1.38.0). The relationship panels a
  // FieldDisplay / FieldInput renders list the related records of the
  // nearest provided record, else of the record in the page URL. A custom
  // override that shows a record the URL may not name (a drawer an action or
  // a lens row opens, a card of another record) wraps its fields in it; a
  // custom create override passes `id: null`, since its record does not
  // exist yet. See docs/overrides.md "Naming the record of the relationship
  // panels". Pair with the NestedParent type re-exported below.
  NestedParentProvider,

  // Shared field-form harness (since v1.20.0). `useMartisForm` owns the
  // form state (values, dependsOn override resolution, errors) and yields
  // the `fieldProps(field)` bundle for a `FieldInput`; `FieldsForm` renders
  // a whole field set (fields + tab_group/section/panel containers). These
  // are the SAME machinery the Resource create/update pages use, so a Tool
  // gets identical field behaviour (slug-from-source, dependsOn, validation
  // display). Bind server-backed behaviour with `resourceKey`/`recordId`; see
  // docs/tool-fields.md. Pair with MartisFormOptions/MartisForm re-exported below.
  useMartisForm,
  FieldsForm,
  useToolFields,

  // Focus/visibility revalidation seam (v1.x+) for Tools that fetch data
  // manually instead of via `useQuery` (which already gets
  // `refetchOnWindowFocus` from the react-query default). Call with a
  // refetch callback to opt a manual-fetch Tool into the same
  // "revalidate when the operator returns to this tab" behaviour.
  useRevalidateOnFocus,

  // Registries (since v1.38.0). The instances the SPA reads, so an
  // extension registers straight into the host: `componentRegistry`
  // (also on `window.Martis.componentRegistry`) for Tools, cards,
  // overrides and field renderers, `iconRegistry` for icons outside the
  // Phosphor set, `layoutRegistry` for the layout every page of one
  // resource renders in. See docs/overrides.md and docs/components.md.
  componentRegistry,
  iconRegistry,
  layoutRegistry,

  // Page and override hooks (since v1.38.0), the ones the package's own
  // pages and drawers use: the tab title of a custom page, the back-button
  // lock a dialog inside a `DrawerShell` needs (it shares the drawers'
  // lock count, so it has to be this instance), the override-props
  // context, the unsaved-changes guard of a custom form, and the error
  // state that parses an `ApiError`. See docs/components.md.
  usePageTitle,
  useModalHistoryLock,
  OverridePropsProvider,
  useOverrideProps,
  useOverridePropsOptional,
  useUnsavedChangesGuard,
  useError,

  // Theme and display helpers (since v1.38.0): theme colours resolved for
  // canvas / Chart.js, which cannot read CSS variables; the avatar
  // palette; the sparkline of the trend cards; the clear button of the
  // inputs; and the loader, as the registry-aware wrapper, so an extension
  // shows the loader the app registered under `loader`. See
  // docs/theming.md and docs/components.md.
  cssVar,
  accentColor,
  mutedTextColor,
  chartPalette,
  resolveColor,
  avatarColorForSeed,
  Sparkline,
  ClearButton,
  MartisLoader,

  // Preferences and locale (since v1.38.0): the user's preference set, the
  // locale switch the Preferences panel runs, the `dir` the shell sets on
  // `<html>` for a locale, and the reduced-motion signal (OS setting or
  // Martis preference). See docs/components.md and docs/i18n.md.
  usePreferences,
  usePreferencesOptional,
  loadLocale,
  applyDocumentDirection,
  usePrefersReducedMotion,

  // Keyboard shortcuts (since v1.38.0): the registry the shell binds its own
  // combos to (`mod+k`, `/`, `shift+?`), so a combo an extension adds shows
  // in the help overlay and takes part in the same conflict order (the first
  // handler registered under a combo runs). `window.Martis.shortcuts` holds
  // the same functions as `add` / `remove` / `list`. See
  // docs/keyboard-shortcuts.md. Pair with the ShortcutOptions and Shortcut
  // types re-exported below.
  addShortcut,
  disableShortcut,
  listShortcuts,

  // Generic slide-over drawer shell. Lets consumer Tools host
  // edit/add/detail forms (composed from FieldInput) in a native
  // drawer without re-implementing the shell — the Tool controls
  // open/close via its own state, like a modal. The resource-bound
  // `martis:drawer-*` registry entries are separate; this is the bare
  // shell. Pair with the DrawerShellProps type re-exported below.
  DrawerShell,

  // PrimeReact Tooltip, the ref-based component for React content (JSX
  // `content`: components, or values JSX escapes). Plain text, and markup
  // the extension writes, go through the global `[data-pr-tooltip]`
  // provider instead, the markup with `data-pr-tooltip-html="true"` on the
  // trigger (rendered as HTML, unsanitised). See docs/components.md
  // "Tooltip Standard". Consumer Tools can't import `primereact/tooltip`
  // (the extension build doesn't alias `primereact`), so it is exposed here.
  Tooltip,

  // PrimeReact filter controls + a portal primitive (since v1.29.0). A
  // consumer Tool can't `import { Dropdown } from 'primereact/dropdown'`
  // (the extension build doesn't alias `primereact`, and bundling a second
  // copy risks version skew). `createPortal` is the host's; the consumer's
  // `react-dom` shim (v1.38.0+) re-exports it, the only part of react-dom it
  // carries. Exposing the exact controls Martis's own filters use (with
  // the `martis-filter-dropdown` styling available via CSS) lets Tools render
  // pixel-identical single/multi filters and portal overlays without
  // hand-replicating PrimeReact's internal DOM. See docs/overrides.md (5.A).
  Dropdown,
  MultiSelect,
  createPortal,
  // `react-dom`'s synchronous flush (since v1.38.2), the host's like
  // `createPortal`: third-party libraries import it from `react-dom` (the
  // list virtualiser `@tanstack/react-virtual` calls it while scrolling),
  // and the consumer's `react-dom` shim re-exports it.
  flushSync,

  // 3rd-party re-exports — consumers don't need to npm install these.
  // Saves ~150 KB across the typical override stub graph and lets us
  // pin a single version of each in the host SPA bundle.
  reactRouterDom: ReactRouterDom,
  reactI18next: ReactI18next,
  tanstackReactQuery: TanstackReactQuery,
} as const

/**
 * The type of the runtime bag: `window.Martis.runtime`, and the default
 * export of `@martis/runtime` in a consumer extension.
 */
export type MartisRuntime = typeof martisRuntime

/**
 * Field renderer types re-exported so consumers calling
 * `runtime.FieldInput` / `runtime.FieldDisplay` can type their props
 * without reaching into internal `@/components/fields/...` paths.
 */
export type { FieldDefinition } from '@/types'
export type { FieldDisplayProps, FieldInputProps } from '@/components/fields/types'
export type { NestedParent } from '@/components/fields/NestedParentContext'
export type { DrawerShellProps } from '@/components/overrides/DrawerShell'
export type { TooltipProps } from 'primereact/tooltip'
export type { DropdownProps } from 'primereact/dropdown'
export type { MultiSelectProps } from 'primereact/multiselect'

/**
 * Shared field-form harness types re-exported so consumer Tools calling
 * `runtime.useMartisForm` / `runtime.FieldsForm` can type their options and
 * form object without reaching into internal `@/hooks/...` paths.
 */
export type { MartisFormOptions, MartisForm } from '@/hooks/useMartisForm'

/**
 * `useToolFields` result type re-exported so consumer Tools calling
 * `runtime.useToolFields` can type their destructured result without
 * reaching into internal `@/hooks/...` paths.
 */
export type { UseToolFieldsResult } from '@/hooks/useToolFields'

/**
 * Event bus payload map re-exported so consumers calling
 * `runtime.martisEventBus.emit(...)` / `.on(...)` get typed event
 * names and payloads without reaching into `@/lib/eventBus` directly.
 */
export type { EventBusEvents } from '@/lib/eventBus'

/**
 * Types for the v1.38.0 members: the props of a layout registered on
 * `runtime.layoutRegistry`, of an override component (what
 * `runtime.useOverrideProps()` returns), and of a loader registered
 * under `loader` or rendered through `runtime.MartisLoader`, with the
 * `loader` config block its `configOverride` overrides.
 */
export type { LayoutProps } from '@/lib/layoutRegistry'
export type { OverrideProps } from '@/types'
export type { MartisLoaderProps } from '@/components/Loader'
export type { MartisLoaderConfig } from '@/lib/config'

/**
 * The `/api/navigation` payload a sidebar or topbar override renders: a
 * group's `items` hold leaf items (`NavigationItem`) and nested groups
 * (`NavigationNestedGroup`, `type: 'group'`). The sidebar override stub
 * types its query with them, and the v1.9.3 stub imported
 * `NavigationGroup` from `@martis/martis/types` (a legacy path the
 * consumer resolves to this module).
 */
export type { NavigationGroup, NavigationGroupChild, NavigationItem, NavigationNestedGroup } from '@/types'

/**
 * The options `runtime.addShortcut` takes, and the registrations
 * `runtime.listShortcuts()` returns.
 */
export type { ShortcutOptions, Shortcut } from '@/lib/keyboardShortcuts'
