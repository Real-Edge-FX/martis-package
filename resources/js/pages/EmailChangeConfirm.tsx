import { useState } from 'react'
import { Link, Navigate, useParams, useSearchParams } from 'react-router'
import { useTranslation } from 'react-i18next'
import { ArrowRightIcon } from '@phosphor-icons/react'
import { useToast } from '@/contexts/ToastContext'
import { api } from '@/lib/api'
import { BASE_PATH } from '@/lib/config'
import { AuthFrame } from '@/components/auth/AuthFrame'

/**
 * Email-change confirmation: the page the link mailed to the NEW address
 * opens (`/profile/email/confirm/{id}?from=&to=&expires=&signature=`).
 *
 * Opening the link changes nothing: a mail scanner, a link preview or a
 * prefetch loads it too, and an attacker may name the address of someone else
 * as the "new" one. The change is the POST this page sends, to the same
 * signed URL, when the person clicks the button (`ProfileEmailChangeController::confirm`,
 * CSRF-protected). The server answers where to go: the profile when this
 * browser is signed in, the login page otherwise, with the outcome in
 * `?email_change=` (toasted there once).
 */
export function EmailChangeConfirmPage() {
  const { id } = useParams()
  const [searchParams] = useSearchParams()
  const { addToast } = useToast()
  const { t } = useTranslation('profile')

  // The signature covers the query string exactly as mailed: keep the raw
  // string and send it back untouched.
  const [search] = useState(() => window.location.search)
  const to = searchParams.get('to') ?? ''
  const [submitting, setSubmitting] = useState(false)

  if (!id || to === '' || !searchParams.has('signature')) {
    return <Navigate to="/login?email_change=invalid" replace />
  }

  async function handleConfirm() {
    setSubmitting(true)
    try {
      const res = await api.post<{ outcome?: string; redirect?: string }>(
        `/profile/email/confirm/${encodeURIComponent(id as string)}${search}`,
      )
      // The change may have switched the identity of the account: a full
      // navigation picks up the new state, and the target page toasts the outcome.
      window.location.href = res?.redirect || BASE_PATH || '/'
    } catch (err) {
      setSubmitting(false)
      addToast('error', err instanceof Error && err.message ? err.message : t('email_change_invalid'))
    }
  }

  return (
    <AuthFrame>
      <h2 className="martis-auth-title">
        {t('email_change_confirm_page_title', { defaultValue: 'Confirm your new email address' })}
      </h2>
      <p className="martis-auth-sub">
        {t('email_change_confirm_page_sub', {
          email: to,
          defaultValue: 'Your account will use {{email}} from now on.',
        })}
      </p>

      <button
        type="button"
        className="martis-btn-primary"
        style={{ width: '100%', height: 40, marginTop: 24 }}
        disabled={submitting}
        onClick={() => void handleConfirm()}
      >
        {submitting
          ? t('email_change_confirm_page_submitting', { defaultValue: 'Confirming…' })
          : t('email_change_confirm_page_submit', { defaultValue: 'Confirm email address' })}
        {!submitting && <ArrowRightIcon size={14} />}
      </button>

      <div style={{ marginTop: 20, textAlign: 'center' }}>
        <Link to="/login" className="martis-auth-forgot">
          {t('email_change_confirm_page_cancel', { defaultValue: 'Not now' })}
        </Link>
      </div>
    </AuthFrame>
  )
}
