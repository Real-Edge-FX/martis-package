declare global {
  interface Window {
    MartisConfig?: {
      basePath?: string
      locale?: string
      brand?: string
    }
  }
}

export const BASE_PATH = window.MartisConfig?.basePath ?? '/martis'
