import { describe, it, expect, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router'

vi.mock('@/components/Sidebar', () => ({ Sidebar: () => <nav>Sidebar</nav> }))
vi.mock('@/components/Topbar', () => ({ Topbar: () => <header>Topbar</header> }))
vi.mock('@/components/Footer', () => ({ Footer: () => <footer /> }))
vi.mock('@/components/ImpersonationBanner', () => ({ ImpersonationBanner: () => null }))
vi.mock('@/components/KeyboardShortcutsHelp', () => ({ KeyboardShortcutsHelp: () => null }))
vi.mock('@/components/NavigationProgress', () => ({ NavigationProgress: () => null }))
vi.mock('@/components/MartisTooltip', () => ({ MartisTooltip: () => null }))
vi.mock('@/contexts/AuthContext', () => ({
  useAuth: () => ({ user: { id: 1, name: 'Ada', email: 'ada@example.com', panel_access: false }, isLoading: false }),
}))

import { Layout } from './Layout'

describe('Layout for a user the viewMartis gate refuses', () => {
  it('renders the no-access screen instead of the shell after a client-side sign-in', () => {
    render(<MemoryRouter><Layout /></MemoryRouter>)

    expect(screen.getByText('No access to this panel')).toBeTruthy()
    expect(screen.queryByText('Sidebar')).toBeNull()
  })
})
