import { useState } from 'react'
import { Link, Navigate, useNavigate } from 'react-router'
import { useTranslation } from 'react-i18next'
import { ArrowRightIcon } from '@phosphor-icons/react'
import { useAuth } from '@/contexts/AuthContext'
import { useToast } from '@/contexts/ToastContext'
import { api, ApiError } from '@/lib/api'
import { BASE_PATH } from '@/lib/config'
import { useAuthLinkParams } from '@/lib/authLink'
import { AuthFrame } from '@/components/auth/AuthFrame'

/**
 * Magic-link confirmation: the page the emailed sign-in link opens
 * (`/magic-link/confirm#email=&token=`). The email and token are in the
 * URL fragment (v2.6.0), which the browser never sends, so they stay out of
 * the request line proxies log; a link with them in the query string is
 * redirected to that form by the server.
 *
 * Opening the link signs nobody in and spends nothing: a mail scanner, a
 * link preview or a prefetch loads it too. The sign-in is the POST this page
 * sends when the person clicks the button (`MagicLinkController::consume`,
 * CSRF-protected), so a link another person planted in a page or a chat
 * cannot swap this browser into their account unseen.
 *
 * A browser signed in as another user is asked a second question first: the
 * sign-in replaces that session, and the server refuses (`409`, token
 * unspent) until the request says `replace_session`. The page knows from
 * the auth context and, when it did not, learns from that 409.
 *
 * The token is read once, then dropped from the address bar so it does not
 * stay in the history or in a screenshot. An expired or invalid token goes
 * to the login page, which says so.
 */
export function MagicLinkConfirmPage() {
  const navigate = useNavigate()
  const { user } = useAuth()
  const { addToast } = useToast()
  const { t } = useTranslation('auth')

  // Read once, then dropped from the address bar.
  const link = useAuthLinkParams()
  const [submitting, setSubmitting] = useState(false)
  const [conflict, setConflict] = useState<string | null>(null)

  if (link.email === '' || link.token === '') {
    return <Navigate to="/login?magic_link=invalid" replace />
  }

  const signedInAs = user?.email ?? null
  const replacing =
    conflict !== null || (signedInAs !== null && signedInAs.toLowerCase() !== link.email.toLowerCase())
  const warning =
    signedInAs !== null && signedInAs.toLowerCase() !== link.email.toLowerCase()
      ? t('magic_link_confirm_replace', {
          current: signedInAs,
          email: link.email,
          defaultValue: 'This browser is signed in as {{current}}. Continuing signs that session out and signs you in as {{email}}.',
        })
      : conflict

  async function handleConfirm() {
    setSubmitting(true)
    try {
      const res = await api.post<{ redirect?: string }>('/api/auth/magic-link/consume', {
        email: link.email,
        token: link.token,
        ...(replacing ? { replace_session: true } : {}),
      })
      // The sign-in just changed the session: a full navigation picks up
      // the new auth state, as the other sign-in pages do.
      window.location.href = res?.redirect || BASE_PATH || '/'
    } catch (err) {
      setSubmitting(false)
      if (err instanceof ApiError && err.status === 409 && err.errors?.[0]?.code === 'session_conflict') {
        setConflict(err.message)
        return
      }
      if (err instanceof ApiError && err.status === 422) {
        navigate(`/login?magic_link=${err.errors?.[0]?.code === 'invalid' ? 'invalid' : 'expired'}`, { replace: true })
        return
      }
      if (err instanceof ApiError && err.status === 404) {
        navigate('/login?magic_link=disabled', { replace: true })
        return
      }
      addToast('error', err instanceof Error && err.message ? err.message : t('error'))
    }
  }

  return (
    <AuthFrame>
      <h2 className="martis-auth-title">
        {t('magic_link_confirm_title', { defaultValue: 'Confirm sign-in' })}
      </h2>
      <p className="martis-auth-sub">
        {t('magic_link_confirm_sub', {
          email: link.email,
          defaultValue: 'You are about to sign in as {{email}}.',
        })}
      </p>

      {warning !== null && (
        <p className="martis-auth-sub" role="alert" style={{ marginTop: 12 }}>
          {warning}
        </p>
      )}

      <button
        type="button"
        className="martis-btn-primary"
        style={{ width: '100%', height: 40, marginTop: 24 }}
        disabled={submitting}
        onClick={() => void handleConfirm()}
      >
        {submitting
          ? t('magic_link_confirm_submitting', { defaultValue: 'Signing in…' })
          : replacing
            ? t('magic_link_confirm_replace_submit', {
                email: link.email,
                defaultValue: 'Sign out and sign in as {{email}}',
              })
            : t('magic_link_confirm_submit', { email: link.email, defaultValue: 'Sign in as {{email}}' })}
        {!submitting && <ArrowRightIcon size={14} />}
      </button>

      <div style={{ marginTop: 20, textAlign: 'center' }}>
        <Link to="/login" className="martis-auth-forgot">
          {t('forgot_password_back_to_login', { defaultValue: 'Back to sign in' })}
        </Link>
      </div>
    </AuthFrame>
  )
}
