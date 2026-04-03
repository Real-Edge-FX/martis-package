export interface MartisThemeConfig {
  default?: 'dark' | 'light'
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
}

export interface MartisDashboardConfig {
  showMetrics?: boolean
  showResourceCards?: boolean
}

export interface MartisConfigShape {
  basePath?: string
  locale?: string
  brand?: string
  logo?: string | null
  theme?: MartisThemeConfig
  userMenu?: MartisUserMenuConfig
  search?: MartisSearchConfig
  dashboard?: MartisDashboardConfig
}

declare global {
  interface Window {
    MartisConfig?: MartisConfigShape
  }
}

export const config: MartisConfigShape = window.MartisConfig ?? {}

export const BASE_PATH = config.basePath ?? '/martis'
