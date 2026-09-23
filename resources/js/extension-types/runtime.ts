/**
 * Type entry for the `@martis/runtime` shim
 * (`stubs/extensions/runtime-shim.mjs.stub`).
 *
 * `npm run build:types` bundles this module into
 * `stubs/extensions/runtime-shim.d.mts.stub`, the declarations
 * `martis:install` publishes next to the shim, so a consumer's
 * `tsc -p tsconfig.extensions.json` types every name the shim exports.
 * Each name is re-exported from the module `martisRuntime.ts` takes it
 * from, so a class (`ApiError`, `Tooltip`) is a type as well as a value.
 * `extensionTypes.test.ts` checks that this module exports exactly the
 * shim's names, each the object the runtime serves, and every type the
 * runtime module exports.
 */

// Hooks, auth errors and provider
export { useAuth, AuthProvider, TwoFactorRequiredError, EmailVerificationRequiredError } from '@/contexts/AuthContext'
export { useToast, useToastSafe } from '@/contexts/ToastContext'
export { useIsMobile } from '@/hooks/useIsMobile'

// Lib
export { api, ApiError } from '@/lib/api'
export { config } from '@/lib/config'
export { martisEventBus } from '@/lib/eventBus'

// Layout components
export { AuthFrame } from '@/components/auth/AuthFrame'
export { Sidebar } from '@/components/Sidebar'
export { Topbar } from '@/components/Topbar'
export { Footer } from '@/components/Footer'

// Composition components
export { FieldInput, FieldDisplay } from '@/components/fields/FieldRenderer'
export { DrawerShell } from '@/components/overrides/DrawerShell'
export { Tooltip } from 'primereact/tooltip'
export { Dropdown } from 'primereact/dropdown'
export { MultiSelect } from 'primereact/multiselect'
export { createPortal, flushSync } from 'react-dom'
export { NestedParentProvider } from '@/components/fields/NestedParentContext'

// Shared field-form harness
export { useMartisForm } from '@/hooks/useMartisForm'
export { FieldsForm } from '@/components/fields/FieldsForm'
export { useToolFields } from '@/hooks/useToolFields'
export { useRevalidateOnFocus } from '@/hooks/useRevalidateOnFocus'

// Registries
export { componentRegistry } from '@/lib/componentRegistry'
export { iconRegistry } from '@/lib/iconRegistry'
export { layoutRegistry } from '@/lib/layoutRegistry'

// Page and override hooks
export { usePageTitle } from '@/hooks/usePageTitle'
export { useModalHistoryLock } from '@/lib/historyLock'
export { OverridePropsProvider, useOverrideProps, useOverridePropsOptional } from '@/hooks/useOverrideProps'
export { useUnsavedChangesGuard } from '@/lib/useUnsavedChangesGuard'
export { useError } from '@/lib/useError'

// Theme and display helpers
export { cssVar, accentColor, mutedTextColor, chartPalette, resolveColor } from '@/lib/themeColors'
export { avatarColorForSeed } from '@/lib/avatarPalette'
export { Sparkline } from '@/components/metrics/Sparkline'
export { ClearButton } from '@/components/ClearButton'
export { MartisLoader } from '@/components/Loader'

// Preferences and locale
export { usePreferences, usePreferencesOptional } from '@/contexts/PreferencesContext'
export { loadLocale, applyDocumentDirection } from '@/lib/i18n'
export { usePrefersReducedMotion } from '@/lib/usePrefersReducedMotion'

// Keyboard shortcuts
export { addShortcut, disableShortcut, listShortcuts } from '@/lib/keyboardShortcuts'

// The third-party hooks the shim flattens (their declarations live in the
// sibling shims' files; the generator points these imports there)
export { Link, NavLink, Outlet, Navigate, useNavigate, useParams, useSearchParams, useLocation } from 'react-router-dom'
export { useTranslation, Trans } from 'react-i18next'
export { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'

export type {
  MartisRuntime,
  FieldDefinition,
  FieldDisplayProps,
  FieldInputProps,
  NestedParent,
  DrawerShellProps,
  TooltipProps,
  DropdownProps,
  MultiSelectProps,
  MartisFormOptions,
  MartisForm,
  UseToolFieldsResult,
  EventBusEvents,
  LayoutProps,
  OverrideProps,
  MartisLoaderProps,
  MartisLoaderConfig,
  NavigationGroup,
  NavigationGroupChild,
  NavigationItem,
  NavigationNestedGroup,
  ShortcutOptions,
  Shortcut,
} from '@/lib/martisRuntime'

export { martisRuntime as default } from '@/lib/martisRuntime'
