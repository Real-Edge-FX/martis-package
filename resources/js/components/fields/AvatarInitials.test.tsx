import { describe, it, expect } from 'vitest'
import { render } from '@testing-library/react'
import type { FieldDefinition } from '@/types'
import { AvatarFieldDisplay, AvatarFieldInput } from './AvatarField'
import { UiAvatarFieldDisplay } from './UiAvatarField'

/*
 * The initials circle of the Avatar and UiAvatar fields is painted with
 * the theme token of the payload's `palette` slot, so a theme recolours it
 * like the Topbar and profile avatars. `color` stays in the payload (the
 * slot's built-in colour, or the `colorFrom()` value) and paints the circle
 * only when there is no slot.
 */

const field = {
  attribute: 'avatar', label: 'Avatar', type: 'avatar',
  nullable: true, readonly: false, required: false, sortable: false,
  searchable: false, showOnIndex: true, showOnDetail: true, showOnForms: true,
  rules: [],
} as unknown as FieldDefinition

const initialsFallback = {
  url: null, name: null, path: null, thumbnailUrl: null,
  isInitialsFallback: true, initials: 'JD', color: '#0891b2', palette: 6, seed: 'Jane Doe',
}

function circle(container: HTMLElement): HTMLElement {
  return container.querySelector('.martis-ui-avatar, .martis-avatar.is-interactive') as HTMLElement
}

describe('UiAvatar initials circle', () => {
  it('paints the palette slot with its theme token', () => {
    const value = { initials: 'JD', color: '#0891b2', palette: 6, seed: 'Jane Doe', shape: 'circle' }
    const { container } = render(<UiAvatarFieldDisplay field={field} value={value} />)

    expect(circle(container).textContent).toBe('JD')
    expect(circle(container).style.backgroundColor).toBe('var(--martis-avatar-6)')
  })

  it('paints a colorFrom() colour, which comes without a slot', () => {
    const value = { initials: 'JD', color: '#ff00aa', palette: null, seed: 'Jane Doe', shape: 'circle' }
    const { container } = render(<UiAvatarFieldDisplay field={field} value={value} />)

    expect(circle(container).style.backgroundColor).toBe('rgb(255, 0, 170)')
  })
})

describe('Avatar initials fallback', () => {
  it('paints the palette slot with its theme token on the index and detail views', () => {
    const { container } = render(<AvatarFieldDisplay field={field} value={initialsFallback} />)

    expect(circle(container).textContent).toBe('JD')
    expect(circle(container).style.backgroundColor).toBe('var(--martis-avatar-6)')
  })

  it('paints the palette slot with its theme token on the form', () => {
    const { container } = render(<AvatarFieldInput field={field} value={initialsFallback} onChange={() => {}} />)

    expect(circle(container).textContent).toBe('JD')
    expect(circle(container).style.backgroundColor).toBe('var(--martis-avatar-6)')
  })

  it('paints a colorFrom() colour, which comes without a slot', () => {
    const value = { ...initialsFallback, color: '#ff00aa', palette: null }
    const { container } = render(<AvatarFieldDisplay field={field} value={value} />)

    expect(circle(container).style.backgroundColor).toBe('rgb(255, 0, 170)')
  })
})
