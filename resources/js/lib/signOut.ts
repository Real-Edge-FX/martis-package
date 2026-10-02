import { api } from '@/lib/api'
import { BASE_PATH } from '@/lib/config'
import { clearAllStickyViews } from '@/lib/useStickyView'
import { discardGuestPreferences } from '@/lib/preferencesStorage'

/**
 * End the session and reload on the login page. A full page load clears
 * the SPA state and brings a fresh CSRF token. A failed logout (the session
 * may already be gone) still lands on the login page.
 *
 * What the browser kept for this session goes with it, so the next person
 * to sign in on the same browser finds none of it: the saved index views
 * (search terms and filter values, in sessionStorage and localStorage) and
 * the preferences cache with its guest-pick marker.
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
  clearAllStickyViews()
  discardGuestPreferences()
  redirect(BASE_PATH + '/login')
}
