/**
 * Runtime configuration injected by the Blade template via window.MartisConfig.
 * All route references MUST use BASE_PATH instead of hardcoded paths.
 */
const cfg = (window as unknown as Record<string, unknown>).MartisConfig as
  | { locale?: string; brand?: string; basePath?: string }
  | undefined

export const BASE_PATH: string = cfg?.basePath ?? "/admin"
