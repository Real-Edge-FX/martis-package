import { useState } from 'react'
import { UserIcon } from '@phosphor-icons/react'
import { avatarPaletteStyle } from '@/lib/avatarPalette'
import type { User } from '@/types'

/**
 * The signed-in user's avatar in the top bar (both layouts): the uploaded
 * picture, else the initials the server computed (`avatar_initials`) on
 * the theme's avatar token of `avatar_palette` — the same letters and
 * colour as the profile page and the user's Avatar / UiAvatar fields. A
 * picture that fails to load falls back to the initials.
 */
export function UserAvatar({ user }: { user: User | null }) {
  const url = user?.avatar_url?.trim() || null
  const [failedUrl, setFailedUrl] = useState<string | null>(null)
  const showPicture = url !== null && url !== failedUrl

  if (showPicture) {
    return (
      <div className="martis-tb-user-avatar" style={{ backgroundColor: 'transparent' }}>
        <img
          src={url}
          alt={user?.name ?? ''}
          style={{ width: '100%', height: '100%', objectFit: 'cover' }}
          onError={() => setFailedUrl(url)}
        />
      </div>
    )
  }

  return (
    <div className="martis-tb-user-avatar" style={avatarPaletteStyle(user?.avatar_palette)}>
      {user?.avatar_initials ? user.avatar_initials : <UserIcon size={14} weight="bold" aria-hidden="true" />}
    </div>
  )
}
