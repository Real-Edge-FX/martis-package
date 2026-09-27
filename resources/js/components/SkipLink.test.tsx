import { describe, it, expect, vi } from 'vitest'
import { render } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router'

// The sidebar shell's chrome is not under test: stand-ins with a focusable
// element each prove the skip link comes before the navigation.
vi.mock('@/components/Sidebar', () => ({ Sidebar: () => <nav><a href="/nav">Nav link</a></nav> }))
vi.mock('@/components/Topbar', () => ({ Topbar: () => <header><button type="button">Top action</button></header> }))
vi.mock('@/components/Footer', () => ({ Footer: () => <footer /> }))
vi.mock('@/components/ImpersonationBanner', () => ({ ImpersonationBanner: () => null }))
vi.mock('@/components/KeyboardShortcutsHelp', () => ({ KeyboardShortcutsHelp: () => null }))
vi.mock('@/components/NavigationProgress', () => ({ NavigationProgress: () => null }))
vi.mock('@/components/MartisTooltip', () => ({ MartisTooltip: () => null }))
vi.mock('@/contexts/AuthContext', () => ({
  useAuth: () => ({ user: { id: 1, name: 'Ada' }, isLoading: false }),
}))

import { Layout } from './Layout'
import { MinimalLayout } from './layouts/MinimalLayout'

describe('the skip link in the sidebar layout', () => {
  it('is the first stop of the Tab order', async () => {
    const user = userEvent.setup()
    render(<MemoryRouter><Layout /></MemoryRouter>)

    await user.tab()

    expect(document.activeElement?.textContent).toBe('Skip to main content')
  })

  it('moves focus to the main landmark without touching the URL', async () => {
    const user = userEvent.setup()
    render(<MemoryRouter><Layout /></MemoryRouter>)
    const hashBefore = window.location.hash

    await user.tab()
    await user.keyboard('{Enter}')

    expect(document.activeElement?.id).toBe('martis-main')
    expect(window.location.hash).toBe(hashBefore)
  })
})

describe('the minimal layout', () => {
  it('has a focusable main landmark and no skip link', () => {
    const { container } = render(<MemoryRouter><MinimalLayout /></MemoryRouter>)

    expect(container.querySelector('main#martis-main')?.getAttribute('tabindex')).toBe('-1')
    expect(container.querySelector('.martis-skip-link')).toBeNull()
  })
})
