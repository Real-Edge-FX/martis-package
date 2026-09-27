import { api } from '@/lib/api'
import { BASE_PATH } from '@/lib/config'

/**
 * End the session and reload on the login page. A full page load clears
 * the SPA state and brings a fresh CSRF token. A failed logout (the session
 * may already be gone) still lands on the login page.
 */
export async function signOut(
  redirect: (url: string) => void = (url) => {
    window.location.href = url
  },
): Promise<void> {
  try {
    await api.post('/api/auth/logout')
  } catch {
    // ignore: the session may already be invalid
  }
  redirect(BASE_PATH + '/login')
}
