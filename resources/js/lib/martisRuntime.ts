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
 * `react-router-dom`, `react-i18next`, `@tanstack/react-query`,
 * `@martis/martis/*` and the legacy `@/contexts/*` / `@/lib/*` /
 * `@/components/auth/*` paths to the same shim. The shim re-exports
 * from `window.Martis.runtime`, so the published bundle reads the
 * runtime off the host's React + context tree at boot time.
 *
 * Adding to this surface: new exports are non-breaking (semver minor).
 * Removing or renaming = breaking (major). Rule of thumb: add only
 * what an override stub actually imports. Power-user code that needs
 * deeper internals can still import from `@/...` directly when the
 * consumer's vite is configured to alias it (default install does).
 *
 * @see docs/runtime-api.md
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
import { FieldsForm } from '@/components/fields/FieldsForm'
import { DrawerShell } from '@/components/overrides/DrawerShell'
import { Tooltip } from 'primereact/tooltip'
import { Dropdown } from 'primereact/dropdown'
import { MultiSelect } from 'primereact/multiselect'
import { createPortal } from 'react-dom'
import { useMartisForm } from '@/hooks/useMartisForm'
import { useToolFields } from '@/hooks/useToolFields'
import { useRevalidateOnFocus } from '@/hooks/useRevalidateOnFocus'

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

  // Generic slide-over drawer shell. Lets consumer Tools host
  // edit/add/detail forms (composed from FieldInput) in a native
  // drawer without re-implementing the shell — the Tool controls
  // open/close via its own state, like a modal. The resource-bound
  // `martis:drawer-*` registry entries are separate; this is the bare
  // shell. Pair with the DrawerShellProps type re-exported below.
  DrawerShell,

  // PrimeReact Tooltip. The global `[data-pr-tooltip]` provider escapes
  // HTML, so rich/HTML tooltip content must use this ref-based component
  // with `escape={false}` (see docs/components.md "Tooltip Standard").
  // Consumer Tools can't import `primereact/tooltip` (the extension build
  // doesn't alias `primereact`), so it is exposed here.
  Tooltip,

  // PrimeReact filter controls + a portal primitive (since v1.29.0). A
  // consumer Tool can't `import { Dropdown } from 'primereact/dropdown'`
  // (the extension build doesn't alias `primereact`, and bundling a second
  // copy risks version skew), and its React shim is React core only (no
  // react-dom). Exposing the exact controls Martis's own filters use — with
  // the `martis-filter-dropdown` styling available via CSS — lets Tools render
  // pixel-identical single/multi filters and portal overlays without
  // hand-replicating PrimeReact's internal DOM. See docs/runtime-api.md.
  Dropdown,
  MultiSelect,
  createPortal,

  // 3rd-party re-exports — consumers don't need to npm install these.
  // Saves ~150 KB across the typical override stub graph and lets us
  // pin a single version of each in the host SPA bundle.
  reactRouterDom: ReactRouterDom,
  reactI18next: ReactI18next,
  tanstackReactQuery: TanstackReactQuery,
} as const

/**
 * Ambient typing so `window.Martis.runtime` is well-typed when the
 * `keyboardShortcuts.ts` `Window` augmentation is loaded.
 */
export type MartisRuntime = typeof martisRuntime

/**
 * Field renderer types re-exported so consumers calling
 * `runtime.FieldInput` / `runtime.FieldDisplay` can type their props
 * without reaching into internal `@/components/fields/...` paths.
 */
export type { FieldDefinition } from '@/types'
export type { FieldDisplayProps, FieldInputProps } from '@/components/fields/types'
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
