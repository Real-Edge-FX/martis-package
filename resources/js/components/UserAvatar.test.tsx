import { describe, it, expect } from 'vitest'
import { render, fireEvent } from '@testing-library/react'
import type { User } from '@/types'
import { UserAvatar } from './UserAvatar'

/*
 * The top bar paints the user's avatar from the server's `avatar_initials`
 * and `avatar_palette` (Martis\Support\Initials), the letters and colour
 * the profile page and the user's Avatar / UiAvatar fields show. It used to
 * compute one letter itself, on the accent colour.
 */

const ada: User = { id: 1, name: 'Ada Lovelace', email: 'ada@example.test', avatar_initials: 'AL', avatar_palette: 11 }

function avatar(container: HTMLElement): HTMLElement {
  return container.querySelector('.martis-tb-user-avatar') as HTMLElement
}

describe('UserAvatar', () => {
  it('shows the server initials on their palette token', () => {
    const { container } = render(<UserAvatar user={ada} />)

    expect(avatar(container).textContent).toBe('AL')
    expect(avatar(container).style.backgroundColor).toBe('var(--martis-avatar-11)')
  })

  it('shows the picture when the user has one', () => {
    const { container } = render(<UserAvatar user={{ ...ada, avatar_url: '/storage/avatars/ada.png' }} />)

    expect(avatar(container).querySelector('img')?.getAttribute('src')).toBe('/storage/avatars/ada.png')
    expect(avatar(container).textContent).toBe('')
  })

  it('falls back to the initials when the picture fails to load', () => {
    const { container } = render(<UserAvatar user={{ ...ada, avatar_url: '/storage/avatars/gone.png' }} />)

    fireEvent.error(avatar(container).querySelector('img') as HTMLImageElement)

    expect(avatar(container).querySelector('img')).toBeNull()
    expect(avatar(container).textContent).toBe('AL')
  })

  it('tries a new picture again after one failed', () => {
    const { container, rerender } = render(<UserAvatar user={{ ...ada, avatar_url: '/storage/avatars/gone.png' }} />)
    fireEvent.error(avatar(container).querySelector('img') as HTMLImageElement)

    rerender(<UserAvatar user={{ ...ada, avatar_url: '/storage/avatars/new.png' }} />)

    expect(avatar(container).querySelector('img')?.getAttribute('src')).toBe('/storage/avatars/new.png')
  })

  it('shows the user glyph on the neutral token without initials', () => {
    const { container } = render(<UserAvatar user={{ id: 2, name: '', email: '' }} />)

    expect(avatar(container).textContent).toBe('')
    expect(avatar(container).querySelector('svg')).not.toBeNull()
    expect(avatar(container).style.backgroundColor).toBe('var(--martis-avatar-16)')
  })
})
