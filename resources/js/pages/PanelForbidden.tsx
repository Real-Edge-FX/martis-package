import { useTranslation } from 'react-i18next'
import { LockIcon, SignOutIcon } from '@phosphor-icons/react'
import { ErrorScreen } from '@/components/auth/ErrorScreen'
import { signOut } from '@/lib/signOut'

/**
 * The whole SPA for a user the `viewMartis` gate refuses (v2.1.0). The
 * server answers the shell with 403 and `MartisConfig.panelForbidden`;
 * `app.tsx` then mounts this screen alone, with no layout and no
 * protected API call, and its one action signs the user out.
 */
export function PanelForbiddenPage() {
  const { t } = useTranslation('messages')
  const { t: tNav } = useTranslation('navigation')

  return (
    <div className="martis-bg flex min-h-screen items-center justify-center p-6">
      <ErrorScreen
        code="403"
        icon={<LockIcon size={32} weight="regular" />}
        title={t('panel_forbidden_title', { defaultValue: 'No access to this panel' })}
        description={t('panel_forbidden_desc', {
          defaultValue: 'Your account cannot open this panel. Sign out and sign in with another account, or ask an administrator for access.',
        })}
        primaryLabel={tNav('logout', { defaultValue: 'Sign out' })}
        primaryIcon={<SignOutIcon size={14} weight="bold" />}
        onPrimary={() => void signOut()}
        showSecondary={false}
      />
    </div>
  )
}
