/**
 * Where the SPA keeps the preferences a person picks before they sign in
 * (theme, language, accent on the login, register and 2FA screens), and the
 * rule for which account those picks may reach.
 *
 * - `martis-preferences` (localStorage): the last preferences applied, so a
 *   reload keeps them. It outlives the tab and the session.
 * - `martis-preferences-guest-modified` (sessionStorage): set when a guest
 *   picked something; it tells the first sign-in that follows to carry the
 *   picks to the account (one `PUT /api/preferences`).
 *
 * The marker is a tab-session value on purpose. In localStorage it survived
 * on a shared browser: the next person to sign in, even days later and in
 * another tab, had the previous visitor's picks written over their own saved
 * preferences. In sessionStorage it reaches only the sign-in made in the tab
 * where the picks were made, which is the person who made them (the flow it
 * exists for: toggle the theme on the login screen, then sign in, even
 * across the full page load a redirect brings). `signOut()` drops the cache
 * and the marker, so neither outlives the session that wrote them.
 */

/** localStorage key of the preferences last applied. */
export const PREFERENCES_CACHE_KEY = 'martis-preferences'

/** Key of the guest-pick marker. sessionStorage now; localStorage before v2.4.0. */
export const GUEST_PICK_KEY = 'martis-preferences-guest-modified'

/** Drop the localStorage flag the earlier versions wrote: it binds to no one, so it is never honoured. */
function dropLegacyGuestFlag(): void {
  try {
    localStorage.removeItem(GUEST_PICK_KEY)
  } catch {
    // localStorage blocked: nothing was kept there.
  }
}

/** Record that a guest changed a preference in this tab session. */
export function markGuestPick(): void {
  dropLegacyGuestFlag()
  try {
    sessionStorage.setItem(GUEST_PICK_KEY, '1')
  } catch {
    // sessionStorage blocked: the picks stay local to this page load.
  }
}

/** Whether a guest changed a preference in this tab session. */
export function hasGuestPick(): boolean {
  dropLegacyGuestFlag()
  try {
    return sessionStorage.getItem(GUEST_PICK_KEY) === '1'
  } catch {
    return false
  }
}

/** Forget the guest-pick marker (the picks were carried over, or discarded). */
export function clearGuestPick(): void {
  dropLegacyGuestFlag()
  try {
    sessionStorage.removeItem(GUEST_PICK_KEY)
  } catch {
    // ignore
  }
}

/**
 * Forget what a guest picked: the marker and the cached preferences. Called
 * on sign-out, so the next visitor of this browser starts from the page's
 * defaults and nothing of this session is carried to their account.
 */
export function discardGuestPreferences(): void {
  clearGuestPick()
  try {
    localStorage.removeItem(PREFERENCES_CACHE_KEY)
  } catch {
    // ignore
  }
}
