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
import { AuthFrame } from '@/components/auth/AuthFrame'
import { Sidebar } from '@/components/Sidebar'
import { Topbar } from '@/components/Topbar'
import { Footer } from '@/components/Footer'
import { FieldInput, FieldDisplay } from '@/components/fields/FieldRenderer'
import { DrawerShell } from '@/components/overrides/DrawerShell'

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

  // Generic slide-over drawer shell. Lets consumer Tools host
  // edit/add/detail forms (composed from FieldInput) in a native
  // drawer without re-implementing the shell — the Tool controls
  // open/close via its own state, like a modal. The resource-bound
  // `martis:drawer-*` registry entries are separate; this is the bare
  // shell. Pair with the DrawerShellProps type re-exported below.
  DrawerShell,

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
