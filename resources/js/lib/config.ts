export interface MartisThemeConfig {
  default?: "dark" | "light"
  allowToggle?: boolean
}

export interface MartisUserMenuConfig {
  showThemeToggle?: boolean
  showProfile?: boolean
  showNotifications?: boolean
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

export interface MartisLayoutConfig {
  /** Layout preset: "sidebar" (default), "topnav", "minimal", "custom" */
  preset?: "sidebar" | "topnav" | "minimal" | "custom"
}

export interface MartisConfigShape {
  basePath?: string
  apiUrl?: string
  locale?: string
  brand?: string
  logo?: string | null
  theme?: MartisThemeConfig
  userMenu?: MartisUserMenuConfig
  search?: MartisSearchConfig
  dashboard?: MartisDashboardConfig
  toast?: MartisToastConfig
  footer?: MartisFooterConfig
  layout?: MartisLayoutConfig
}

declare global {
  interface Window {
    MartisConfig?: MartisConfigShape
  }
}

export const config: MartisConfigShape = window.MartisConfig ?? {}

export const BASE_PATH = config.basePath ?? "/martis"

/**
 * Absolute API base URL. When apiUrl is set (from APP_URL), API requests use
 * absolute URLs so the app works correctly even when accessed from a different
 * domain (e.g. www.realedgefx.com proxying to martis.realedgefx.com).
 */
export const API_BASE_URL = config.apiUrl
  ? config.apiUrl.replace(/\/$/, '') + BASE_PATH
  : BASE_PATH
