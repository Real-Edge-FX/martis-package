export interface MartisThemeConfig {
  default?: "dark" | "light"
  allowToggle?: boolean
}

/**
 * Keyboard-shortcuts subsystem boot toggles.
 *
 * - `enabled` — master switch. When false, `addShortcut()` becomes a
 *   no-op everywhere (the bundled `mod+k`, `/`, and `shift+?` combos
 *   included). Use it on installs that ship a custom keyboard layer
 *   or want to forbid global hotkeys outright.
 * - `helpOverlay` — when false, the `shift+?` help overlay is NOT
 *   registered. `addShortcut()` itself stays live for everything else,
 *   so this is the right knob if the host app wants to expose its own
 *   help UI instead of the bundled dialog.
 */
export interface MartisKeyboardShortcutsConfig {
  enabled?: boolean
  helpOverlay?: boolean
}

export interface MartisUserMenuConfig {
  showThemeToggle?: boolean
  showProfile?: boolean
  customItems?: Array<{
    label: string
    icon?: string
    url?: string
    separator?: boolean
  }>
}

export interface MartisSearchConfig {
  enabled?: boolean
  placeholder?: string
  /** Display mode: "bar" = full search bar, "icon" = icon-only button, "disabled" = hidden */
  mode?: "bar" | "icon" | "disabled"
  /** Display mode on mobile viewports (<=768px). Defaults to "icon". */
  mobileMode?: "bar" | "icon" | "disabled"
}

export interface MartisDashboardConfig {
  /** Show the personalised greeting ("Hello, {name}") on the dashboard. Default: true. */
  showGreeting?: boolean
  /** Show the welcome subtitle below the greeting. Default: true. */
  showWelcome?: boolean
  /** Show the animated welcome hero card at the top of the default dashboard. Default: true. */
  showWelcomeCard?: boolean
  showMetrics?: boolean
  showResourceCards?: boolean
}

export interface MartisToastConfig {
  position?: "top-right" | "top-left" | "bottom-right" | "bottom-left" | "top-center" | "bottom-center"
}

export interface MartisFooterConfig {
  enabled?: boolean
  /** Custom footer text. null = auto-generate from brand.name */
  text?: string | null
}

/**
 * Welcome card content overrides.
 *
 * When set, these values win over the `welcome_card_heading` /
 * `welcome_card_description` translations on the default dashboard.
 * Useful for branded SaaS deployments that want a single source of
 * truth via env vars (`MARTIS_WELCOME_HEADING`,
 * `MARTIS_WELCOME_DESCRIPTION`) instead of publishing the lang files.
 *
 * For per-locale customization, leave both `null` and override the
 * `martis::resources` translations via `vendor:publish --tag=martis-lang`.
 */
export interface MartisWelcomeConfig {
  heading?: string | null
  description?: string | null
}

export interface MartisDrawerConfig {
  /** Default width for every DrawerOverride (override per-resource via `->width()`). */
  width?: string
  /** Width when the user toggles the drawer into its expanded state. */
  expandedWidth?: string
  /**
   * Global gate for the expand / fullscreen buttons. When `false`, both
   * controls are suppressed on every drawer regardless of the per-drawer
   * `allowExpand` / `allowFullscreen` props. Default: true.
   */
  expandable?: boolean
}

export interface MartisLoaderConfig {
  /** Custom loading message. Default: translation key 'messages:loading' */
  message?: string
  /** Phosphor icon name to use instead of spinner */
  icon?: string
  /** URL to logo image to use instead of spinner */
  logo?: string
  /** CSS color for the spinner. Default: var(--martis-accent) */
  spinnerColor?: string
  /** Overlay background opacity (0-1). Default: 0.6 */
  overlayOpacity?: number
  /** CSS color for overlay background. Default: var(--martis-bg) */
  overlayColor?: string
  /** Disable loaders globally */
  disabled?: boolean
  /** Disable loader on specific contexts */
  disableOn?: {
    table?: boolean
    fields?: boolean
    search?: boolean
    components?: boolean
    detail?: boolean
  }
}

export interface MartisNavigationConfig {
  /**
   * Interval in milliseconds at which the sidebar and top-nav menus
   * re-fetch the LIGHTWEIGHT badges endpoint
   * (`/api/navigation/badges`). Keeps resource count badges in sync
   * without re-pulling the full navigation tree (which rarely changes
   * in production).
   *
   * The full navigation payload is fetched once per session and on
   * route mutations — it is NOT auto-polled by design. The badges
   * payload is a flat `{ uriKey: count }` map and is 5-10× cheaper
   * server-side than the full tree.
   *
   * Set to `0` to disable badge polling entirely. Default: 300000
   * (5 minutes).
   */
  badgesPollInterval?: number
  /**
   * Threshold above which count badges switch from full digits
   * (1,284) to compact notation (10K, 1.2M). `null` = always full.
   * Default: 10000.
   */
  countCompactThreshold?: number | null
}

export interface MartisLayoutConfig {
  /** Layout preset: "sidebar" (default), "topnav", "minimal", "custom" */
  preset?: "sidebar" | "topnav" | "minimal" | "custom"
  /**
   * Optional registry-key overrides for individual shell pieces. Each
   * value is a key registered via `componentRegistry.register(...)` in
   * the consumer's `boot.ts`. Null keeps the bundled piece.
   */
  components?: {
    shell?: string | null
    sidebar?: string | null
    topbar?: string | null
    footer?: string | null
  }
}

export interface MartisProfileMenuConfig {
  enabled?: boolean
  label?: string
  icon?: string
}

export interface MartisProfileAvatarConfig {
  enabled?: boolean
  /** Maximum allowed upload size in kilobytes. Mirrors `martis.profile.avatar.max_size_kb`. Default: 2048 (2 MB). */
  max_size_kb?: number
}

export interface MartisProfileTwoFactorConfig {
  enabled?: boolean
}

export interface MartisProfileConfig {
  /** Whether the profile page and its backend routes are enabled. Default: true. */
  enabled?: boolean
  /** Ordered list of sections to render. Supported: 'account', 'password', 'avatar', 'security', 'sessions'. */
  sections?: string[]
  menu?: MartisProfileMenuConfig
  avatar?: MartisProfileAvatarConfig
  two_factor?: MartisProfileTwoFactorConfig
}

/**
 * Bridge for the PHP-side `martis.locales.*` config block. Surfaced
 * verbatim so the React shell can short-circuit a server round-trip
 * when deciding whether the active locale should render right-to-left.
 *
 * The TranslationsController already encodes app namespaces and the
 * fallback chain in the `/api/translations/{locale}` payload; the
 * matching keys here are exposed mostly for symmetry / debug overlays.
 */
export interface MartisLocalesConfig {
  /** Extra translation namespaces merged in by the TranslationsController. */
  appNamespaces?: string[]
  /** Ordered fallback chain searched when a key is missing. */
  fallbackChain?: string[]
  /**
   * Locale codes that should render the panel in right-to-left layout.
   * The shell matches the active i18next language against this list and
   * writes `dir="rtl"` on `<html>` so the bundled CSS (logical
   * properties) flips margins / paddings / borders automatically.
   */
  rtlLocales?: string[]
}

export interface MartisPreferencesInitialPayload {
  theme?: 'dark' | 'light' | 'system'
  accent?: 'martis' | 'blue' | 'teal' | 'violet' | 'amber' | 'custom'
  brandColor?: string | null
  density?: 'comfortable' | 'dense'
  locale?: string
  reducedMotion?: boolean
  source?: 'default' | 'user' | 'preset'
  preset?: string | null
}

/** A single custom accent declared via `MARTIS_CUSTOM_ACCENTS` (v1.7.0).
 *  The hex value already passed server-side validation (#RRGGBB). */
export interface MartisCustomAccent {
  name: string
  color: string
}

export interface MartisPreferencesConfig {
  enabled: boolean
  allowBrandColor: boolean
  /** Locale codes the language picker offers (`martis.preferences.locales`).
   *  Available before login (unlike the authenticated `/api/preferences`
   *  meta), so the login picker can honour the configured restriction. */
  locales?: string[]
  /** Map of locale code → human-readable label (e.g. `en` → "English"). */
  localeLabels?: Record<string, string>
  initial: MartisPreferencesInitialPayload | null
  /** Custom accent swatches surfaced in the PreferencesMenu picker (v1.7.0). */
  customAccents?: MartisCustomAccent[]
}

/** The locales Martis ships translations for — the last-resort fallback when
 *  neither the authenticated meta nor `preferences.locales` is available. */
export const BUNDLED_LOCALES = ['en', 'pt_PT', 'pt_BR']

/**
 * Resolve the locale list a language picker should offer. Precedence:
 * authenticated `meta.locales` (from `/api/preferences`, reflects
 * `martis.preferences.locales`) → the pre-login `preferences.locales`
 * bootstrapped into `window.MartisConfig` → the bundled default. This keeps
 * the login picker (where `meta` is null) honouring the configured
 * restriction instead of always showing all three bundled locales.
 */
export function resolvePickerLocales(metaLocales?: string[] | null): string[] {
  if (metaLocales && metaLocales.length > 0) return metaLocales
  const configured = config.preferences?.locales
  if (configured && configured.length > 0) return configured
  return BUNDLED_LOCALES
}

/** Generic shape for each alternative auth flow (SSO, Google, password reset,
 *  registration). `enabled` renders the UI piece; `url` is where the button
 *  or link points. When `enabled` is `true` but `url` is empty, the UI
 *  surfaces an "not configured" toast to the programmer. */
export interface MartisAuthFlowConfig {
  enabled?: boolean
  url?: string | null
}

/** Visibility toggles for the compact guest-mode controls (theme cycle,
 *  language picker) rendered in the top-right of every auth surface
 *  (Login, Register, 2FA, error pages). Hiding a control does not change
 *  its underlying preference value — only the widget. */
export interface MartisAuthControlsConfig {
  theme?: boolean
  locale?: boolean
}

/** Per-provider SSO config (Task 14). The Login page renders one
 *  button per enabled provider; the URL points at
 *  `/{martis-path}/sso/{provider-name}/redirect`. */
export interface MartisSsoProviderConfig {
  /** Master switch for this provider. */
  enabled?: boolean
  /** Human-readable button label. */
  label?: string | null
  /** Phosphor icon name. */
  icon?: string | null
}

export interface MartisSsoConfig {
  /** Master switch — when `false`, no SSO buttons render and the
   *  `/sso/*` routes are not registered server-side either. */
  enabled?: boolean
  /** Map of provider-name → per-provider config. */
  providers?: Record<string, MartisSsoProviderConfig>
}

/** A copy override entry. Three accepted shapes (since v1.8.5):
 *   - string                              → applied verbatim
 *   - `Record<locale, string>`            → resolved against the active
 *                                           i18n language at render time
 *   - null / undefined                    → falls back to the bundled translation
 */
export type MartisAuthCopyEntry = string | Record<string, string> | null

/** Per-page copy overrides for the unauthenticated auth surfaces. v1.8.0 / v1.8.5. */
export interface MartisAuthCopyConfig {
  login?: {
    title?: MartisAuthCopyEntry
    subtitle?: MartisAuthCopyEntry
    /** Used instead of `subtitle` when SSO is enabled. */
    subtitle_with_sso?: MartisAuthCopyEntry
  }
  register?: {
    title?: MartisAuthCopyEntry
    subtitle?: MartisAuthCopyEntry
  }
  forgot_password?: {
    title?: MartisAuthCopyEntry
    subtitle?: MartisAuthCopyEntry
  }
  reset_password?: {
    title?: MartisAuthCopyEntry
    subtitle?: MartisAuthCopyEntry
  }
}

export interface MartisAuthConfig {
  /** SSO subsystem. Replaces the legacy `sso` / `google` flat blocks. */
  sso?: MartisSsoConfig
  /** "Forgot?" link next to the password label. */
  passwordReset?: MartisAuthFlowConfig
  /** Self-service registration — gates the `/register` route and the
   *  "Create an account" link on Login. */
  registration?: MartisAuthFlowConfig
  /** Visibility of the theme cycle button and the language picker on
   *  every pre-login surface. Both default to `true`. */
  controls?: MartisAuthControlsConfig
  /** Optional per-page copy overrides. v1.8.0. */
  copy?: MartisAuthCopyConfig
  /** Magic-link (passwordless) sign-in. v1.8.8. */
  magicLink?: MartisMagicLinkConfig
  /** Email-verification subsystem state. The SPA branches on this for
   *  the post-register UX (toast copy + redirect) and for the verify
   *  notice page when no session is active. v1.8.16. */
  emailVerification?: {
    enabled?: boolean
  }
}

export interface MartisMagicLinkConfig {
  /** When true the Login page shows the "Email me a sign-in link" button. */
  enabled?: boolean
  /** Minutes the emailed token stays valid; surfaced to the UI for copy. */
  ttlMinutes?: number
}

export interface MartisConfigShape {
  basePath?: string
  locale?: string
  brand?: string
  /**
   * Full horizontal brand lockup (icon + wordmark in one asset). When
   * set, the SPA renders the lockup alone — the separate `brand` text
   * next to the icon is hidden in the sidebar / topbar / auth frame to
   * avoid a duplicated wordmark.
   */
  logo?: string | null
  /**
   * Small square brand icon. Used in compact surfaces (collapsed
   * sidebar, login frame, mobile shell) where a horizontal lockup
   * would clip. When null, falls back to the bundled Martis cube.
   * Independent from `logo` so the consumer can ship both — Martis
   * prefers `logo` when both are set.
   */
  icon?: string | null
  /**
   * Theme-aware variants (v1.7.0). When `logoDark` / `iconDark` is
   * set, the SPA renders both the light and dark variants in the
   * DOM and CSS hides one based on `<html data-theme>`. If only
   * one variant of a pair is set, it is used for both themes.
   */
  logoDark?: string | null
  iconDark?: string | null
  /**
   * Per-surface logo height (v1.7.0). Drives a CSS variable on
   * `:root`. Defaults: menu 40px, auth 48px. Server clamps the
   * range to 20-56 (menu) and 24-80 (auth) so an absurd .env
   * value cannot break the layout.
   */
  logoHeight?: { menu?: number; auth?: number }
  /** Martis package version surfaced in the sidebar footer. */
  version?: string
  /** Optional link to the project's docs shown in the sidebar footer. */
  docsUrl?: string | null
  theme?: MartisThemeConfig
  userMenu?: MartisUserMenuConfig
  search?: MartisSearchConfig
  dashboard?: MartisDashboardConfig
  toast?: MartisToastConfig
  footer?: MartisFooterConfig
  welcome?: MartisWelcomeConfig
  drawer?: MartisDrawerConfig
  layout?: MartisLayoutConfig
  navigation?: MartisNavigationConfig
  loader?: MartisLoaderConfig
  profile?: MartisProfileConfig
  preferences?: MartisPreferencesConfig
  /**
   * Locale extensibility knobs surfaced to the SPA so the shell can
   * react to RTL locales, app-level namespaces, and the configured
   * fallback chain without an extra round-trip.
   */
  locales?: MartisLocalesConfig
  auth?: MartisAuthConfig
  stickyViews?: MartisStickyViewsConfig
  notifications?: MartisNotificationsConfig
  impersonation?: MartisImpersonationConfig
  keyboardShortcuts?: MartisKeyboardShortcutsConfig
  /**
   * Runtime extension URLs (v1.8.19+). Each entry is dynamically
   * imported as an ESM module after the bundled `componentRegistry`
   * is exposed on `window.Martis`. Consumer-built bundles register
   * their components from inside these scripts. Sourced from the
   * `MARTIS_EXTENSIONS` env (comma-separated) → `config.martis.extensions`.
   */
  extensions?: string[]
  /**
   * Developer tooling switches. Today this only carries the gate
   * for the Component Inspector at `/dev/components`; future dev
   * surfaces (route inspector, schema browser, etc.) hang here too.
   */
  dev?: {
    /**
     * Whether the Component Inspector route is mounted. Defaults
     * to true on `local` / `testing` environments and false
     * everywhere else; the host can force either value via the
     * `MARTIS_DEV_TOOLS` env var.
     */
    toolsEnabled?: boolean
  }
  /**
   * Per-resource record URL templates, keyed by `uriKey`. Used by
   * `recordHref()` to resolve a record's destination when it diverges
   * from the default `/resources/{uriKey}/{id}` path (e.g. a headless
   * resource that should link to its owning Tool instead). Each
   * template may contain an `{id}` placeholder.
   */
  resourceRecordUrls?: Record<string, string>
}

/**
 * Impersonation subsystem boot metadata.
 *
 * The banner short-circuits its `/api/impersonation/status` poll when
 * `enabled` is false at boot, so a host app that never enables the
 * feature pays zero ongoing cost (one boot read of the flag, no
 * round-trips per page navigation). Mid-session enable still works —
 * the banner re-mounts on the next page load and starts polling.
 */
export interface MartisImpersonationConfig {
  /**
   * Mirrors `martis.impersonation.enabled`. When false the banner
   * skips its mount-time fetch entirely.
   */
  enabled?: boolean
  /**
   * Polling interval in milliseconds for the
   * `/api/impersonation/status` endpoint. Sessions change rarely;
   * default is 120000 (2 minutes). Set to 0 to disable polling — the
   * banner still mounts and reads state once per page load.
   */
  pollInterval?: number
}

/**
 * In-app notifications subsystem (the bell dropdown in the topbar).
 * Backed by Laravel's standard `notifications` table — any
 * Notification class delivered via the `database` channel surfaces
 * here automatically.
 */
export interface MartisNotificationsConfig {
  /** Master switch. When false, the bell never renders. */
  enabled?: boolean
  /**
   * Polling interval for the unread-count badge in milliseconds.
   * Default: 90000 (90 seconds). Set to 0 to disable polling
   * (consumers can refresh manually via React Query, e.g. when a
   * Pusher / Reverb broadcast event fires).
   */
  poll_interval?: number
  /**
   * Maximum number of notifications shown in the dropdown panel.
   * Older entries live behind a future "View all" page. Capped at
   * 50 server-side regardless of this value.
   */
  max_in_dropdown?: number
}

/**
 * Per-user view state persistence (filters / sort / pagination / etc.)
 * on resource index pages. URL params remain the source of truth and
 * deep-link friendly; sessionStorage (or localStorage) is the
 * tab-scoped memory of "last state per resource" so navigating to a
 * detail page and back restores the previous view automatically.
 */
export interface MartisStickyViewsConfig {
  /** Master switch. Falsey disables the entire feature. */
  enabled?: boolean
  /**
   * Where the per-resource state lives:
   *   - `session` (default) — sessionStorage. Wipes on tab close.
   *   - `local` — localStorage. Survives tab close.
   *   - `server` — reserved for the next iteration; not yet wired.
   */
  scope?: 'session' | 'local' | 'server'
  /** Per-bucket toggles. Set any to false to keep that bucket un-sticky. */
  persist?: {
    filters?: boolean
    sorting?: boolean
    pagination?: boolean
    per_page?: boolean
    columns?: boolean
    scroll?: boolean
  }
}

declare global {
  interface Window {
    MartisConfig?: MartisConfigShape
  }
}

export const config: MartisConfigShape = window.MartisConfig ?? {}

export const BASE_PATH = config.basePath ?? "/martis"

/**
 * API base URL. Uses the current page origin explicitly to guarantee API
 * requests always target the same server the page was loaded from, regardless
 * of proxies, caches, or DNS configuration.
 */
export const API_BASE_URL = `${window.location.origin}${BASE_PATH}`
