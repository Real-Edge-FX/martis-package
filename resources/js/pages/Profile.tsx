import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { api } from '@/lib/api'
import { config } from '@/lib/config'
import { useAuth } from '@/contexts/AuthContext'
import { AccountSection } from '@/components/Profile/AccountSection'
import { PasswordSection } from '@/components/Profile/PasswordSection'
import { AvatarSection } from '@/components/Profile/AvatarSection'
import { SecuritySection } from '@/components/Profile/SecuritySection'
import { MartisLoader } from '@/components/Loader'

interface ProfileData {
  name: string
  email: string
  avatar_url: string | null
  two_factor_enabled: boolean
}

export function ProfilePage() {
  const { t } = useTranslation('profile')
  const { user } = useAuth()
  const [profile, setProfile] = useState<ProfileData | null>(null)
  const [loading, setLoading] = useState(true)

  const avatarEnabled = config.profile?.avatar?.enabled !== false
  const twoFactorEnabled = config.profile?.two_factor?.enabled !== false

  useEffect(() => {
    api
      .get<ProfileData>('/api/profile')
      .then((data) => setProfile(data))
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
        <h1 className="text-2xl font-bold martis-text selection:bg-primary/20 selection:text-foreground">
          {t('title')}
        </h1>
      </div>

      <div className="space-y-6 max-w-2xl">
        {profile && (
          <>
            {avatarEnabled && (
              <AvatarSection
                avatarUrl={profile.avatar_url}
                name={profile.name}
                onUpdate={(url) => setProfile((p) => p ? { ...p, avatar_url: url } : p)}
              />
            )}

            <AccountSection
              name={profile.name}
              email={profile.email}
              onUpdate={(name, email) =>
                setProfile((p) => p ? { ...p, name, email } : p)
              }
            />

            <PasswordSection />

            {twoFactorEnabled && (
              <SecuritySection
                twoFactorEnabled={profile.two_factor_enabled}
                onUpdate={(enabled) =>
                  setProfile((p) => p ? { ...p, two_factor_enabled: enabled } : p)
                }
              />
            )}
          </>
        )}
      </div>
    </div>
  )
}
