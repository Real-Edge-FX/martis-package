export interface MartisThemeConfig {
  default?: "dark" | "light"
  allowToggle?: boolean
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
   * re-fetch the navigation endpoint while the tab is focused. Keeps
   * resource count badges in sync when a second user mutates data.
   *
   * Set to `0` to disable polling. Default: 60000 (60 seconds).
   */
  pollInterval?: number
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
  maxSize?: number
}

export interface MartisProfileTwoFactorConfig {
  enabled?: boolean
}

export interface MartisProfileConfig {
  /** Whether the profile page and its backend routes are enabled. Default: true. */
  enabled?: boolean
  /** Ordered list of sections to render. Supported: 'account', 'password', 'avatar', 'security'. */
  sections?: string[]
  menu?: MartisProfileMenuConfig
  avatar?: MartisProfileAvatarConfig
  two_factor?: MartisProfileTwoFactorConfig
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

export interface MartisPreferencesConfig {
  enabled: boolean
  allowBrandColor: boolean
  /** Map of locale code → human-readable label (e.g. `en` → "English"). */
  localeLabels?: Record<string, string>
  initial: MartisPreferencesInitialPayload | null
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
}

export interface MartisConfigShape {
  basePath?: string
  locale?: string
  brand?: string
  logo?: string | null
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
  drawer?: MartisDrawerConfig
  layout?: MartisLayoutConfig
  navigation?: MartisNavigationConfig
  loader?: MartisLoaderConfig
  profile?: MartisProfileConfig
  preferences?: MartisPreferencesConfig
  auth?: MartisAuthConfig
  stickyViews?: MartisStickyViewsConfig
  notifications?: MartisNotificationsConfig
  impersonation?: MartisImpersonationConfig
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
   * Set to 0 to disable polling (consumers can refresh manually
   * via React Query, e.g. when a Pusher / Reverb broadcast event
   * fires).
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
