/**
 * The outcome of the confirmation link of a new email address (v2.4.0).
 *
 * `ProfileEmailChangeController` redirects to the profile page, or to the
 * login page when the browser is not signed in, with `?email_change=` and one
 * of these. The page toasts it once and takes the flag out of the address.
 */
export type EmailChangeOutcome = 'changed' | 'invalid' | 'rejected'

/** The English wording, for a locale that does not carry `profile.email_change_*`. */
export const EMAIL_CHANGE_FALLBACKS: Record<EmailChangeOutcome, string> = {
  changed: 'Your email address was changed.',
  invalid: 'This confirmation link is invalid, has expired or was already used.',
  rejected: 'The new email address can no longer be used. Choose another one.',
}

/** The outcome named by a query string, or null when it names none. */
export function emailChangeOutcome(search: string): EmailChangeOutcome | null {
  const value = new URLSearchParams(search).get('email_change')

  return value === 'changed' || value === 'invalid' || value === 'rejected' ? value : null
}
