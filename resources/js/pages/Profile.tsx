import { useEffect, useState } from 'react'
import { UserCircleIcon } from '@phosphor-icons/react'
import { useTranslation } from 'react-i18next'
import { api } from '@/lib/api'
import { config } from '@/lib/config'
import { useAuth } from '@/contexts/AuthContext'
import { AccountSection } from '@/components/Profile/AccountSection'
import { PasswordSection } from '@/components/Profile/PasswordSection'
import { AvatarSection } from '@/components/Profile/AvatarSection'
import { SecuritySection } from '@/components/Profile/SecuritySection'
import { BrowserSessionsSection as BundledBrowserSessionsSection } from '@/components/Profile/BrowserSessionsSection'
import { componentRegistry } from '@/lib/componentRegistry'
import { MartisLoader } from '@/components/Loader'
import { usePageTitle } from '@/hooks/usePageTitle'

interface ProfileData {
  name: string
  email: string
  avatar_url: string | null
  two_factor_enabled: boolean
}

export function ProfilePage() {
  const { t } = useTranslation('profile')
  const { t: tNav } = useTranslation('navigation')
  const { user, updateUser } = useAuth()
  const [profile, setProfile] = useState<ProfileData | null>(null)
  const [loading, setLoading] = useState(true)

  usePageTitle(tNav('profile', { defaultValue: 'Profile' }))

  const avatarEnabled = config.profile?.avatar?.enabled !== false
  const twoFactorEnabled = config.profile?.two_factor?.enabled !== false
  const sections = config.profile?.sections ?? ['avatar', 'account', 'password', 'security', 'sessions']

  useEffect(() => {
    api
      .get<ProfileData>('/api/profile')
      .then((data) => {
        setProfile(data)
        // Sync avatar_url to global auth context so Topbar updates
        updateUser({ avatar_url: data.avatar_url })
      })
      .catch(() => {
        // Use auth user data as fallback while backend is not ready
        setProfile({
          name: user?.name ?? '',
          email: user?.email ?? '',
          avatar_url: null,
          two_factor_enabled: false,
        })
      })
      .finally(() => setLoading(false))
  }, [])

  if (loading) {
    return (
      <div className="flex items-center justify-center h-40">
        <MartisLoader />
      </div>
    )
  }

  return (
    <div>
      <div className="mb-6">
        <h1 className="text-2xl font-bold martis-text flex items-center gap-2 selection:bg-primary/20 selection:text-foreground">
          <UserCircleIcon size={28} className="martis-text-muted flex-shrink-0" />
          {t('title')}
        </h1>
        <p className="text-sm martis-text-muted mt-1">{t('subtitle')}</p>
      </div>

      <div className="space-y-6">
        {profile && sections.map((section) => {
          switch (section) {
            case 'avatar':
              return avatarEnabled ? (
                <AvatarSection
                  key="avatar"
                  avatarUrl={profile.avatar_url}
                  name={profile.name}
                  onUpdate={(url) => {
                    setProfile((p) => p ? { ...p, avatar_url: url } : p)
                    updateUser({ avatar_url: url })
                  }}
                />
              ) : null
            case 'account':
              return (
                <AccountSection
                  key="account"
                  name={profile.name}
                  email={profile.email}
                  onUpdate={(name, email) =>
                    setProfile((p) => p ? { ...p, name, email } : p)
                  }
                />
              )
            case 'password':
              return <PasswordSection key="password" />
            case 'security':
              return twoFactorEnabled ? (
                <SecuritySection
                  key="security"
                  twoFactorEnabled={profile.two_factor_enabled}
                  onUpdate={(enabled) =>
                    setProfile((p) => p ? { ...p, two_factor_enabled: enabled } : p)
                  }
                />
              ) : null
            case 'sessions': {
              // Allow consumer override under the canonical registry
              // key. When unset the bundled component renders.
              const Override = componentRegistry.has('martis:profile-sessions')
                ? (componentRegistry.resolve('martis:profile-sessions') as React.ComponentType | undefined)
                : undefined
              const Section = Override ?? BundledBrowserSessionsSection
              return <Section key="sessions" />
            }
            default:
              return null
          }
        })}
      </div>
    </div>
  )
}
