import { useTranslation } from 'react-i18next'
import { LockIcon, SignOutIcon, UserSwitchIcon } from '@phosphor-icons/react'
import { ErrorScreen } from '@/components/auth/ErrorScreen'
import { api } from '@/lib/api'
import { config } from '@/lib/config'
import { signOut } from '@/lib/signOut'

/**
 * Hand an impersonated session back to the operator, then reload so the
 * operator's own panel access decides what they see. A failed stop still
 * ends the session, through the sign-out.
 */
export async function stopImpersonating(
  reload: () => void = () => window.location.reload(),
): Promise<void> {
  try {
    await api.post('/api/impersonation/stop', {})
  } catch {
    await signOut()
    return
  }
  reload()
}

/**
 * The whole SPA for a user the `viewMartis` gate refuses (v2.1.0). The
 * server answers the shell with 403 and `MartisConfig.panelForbidden`;
 * `app.tsx` then mounts this screen alone, with no layout and no
 * protected API call. Its one action signs the user out or, when the
 * refused user is being impersonated (`panelForbiddenImpersonating`),
 * stops the impersonation, the one API route the gate lets through then.
 */
export function PanelForbiddenPage() {
  const { t } = useTranslation('messages')
  const { t: tNav } = useTranslation('navigation')
  const impersonating = config.panelForbiddenImpersonating === true

  return (
    <div className="martis-bg flex min-h-screen items-center justify-center p-6">
      <ErrorScreen
        code="403"
        icon={<LockIcon size={32} weight="regular" />}
        title={t('panel_forbidden_title', { defaultValue: 'No access to this panel' })}
        description={t('panel_forbidden_desc', {
          defaultValue: 'Your account cannot open this panel. Sign out and sign in with another account, or ask an administrator for access.',
        })}
        primaryLabel={impersonating
          ? t('impersonation_stop', { defaultValue: 'Stop impersonating' })
          : tNav('logout', { defaultValue: 'Sign out' })}
        primaryIcon={impersonating
          ? <UserSwitchIcon size={14} weight="bold" />
          : <SignOutIcon size={14} weight="bold" />}
        onPrimary={() => void (impersonating ? stopImpersonating() : signOut())}
        showSecondary={false}
      />
    </div>
  )
}
