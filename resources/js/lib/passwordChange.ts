import { BASE_PATH, config } from '@/lib/config'

/** The SPA route of the built-in forced password change page (v2.3.0). */
export const PASSWORD_CHANGE_ROUTE = '/password/change'

/**
 * Where a user the forced password change gate holds goes: the app's
 * `martis.auth.password_change.url`, else the built-in page.
 */
export function passwordChangeUrl(): string {
  const configured = config.auth?.passwordChange?.url
  return typeof configured === 'string' && configured !== '' ? configured : BASE_PATH + PASSWORD_CHANGE_ROUTE
}

/** Whether `url` is the built-in page, which the SPA router opens without a reload. */
export function isBuiltInPasswordChangeUrl(url: string = passwordChangeUrl()): boolean {
  return url === BASE_PATH + PASSWORD_CHANGE_ROUTE
}

/** Whether the browser already shows the page `url` names. */
export function isOnPage(url: string): boolean {
  return window.location.pathname === new URL(url, window.location.origin).pathname
}
