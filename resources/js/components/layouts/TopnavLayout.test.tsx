import { describe, it, expect, vi, afterEach } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'

/*
 * The topnav's search button is an icon: its accessible name is its
 * aria-label, which is translated like the rest of the navigation (it was
 * the fixed English "Search").
 */

vi.mock('react-i18next', async (importOriginal) => {
  const actual = await importOriginal<typeof import('react-i18next')>()
  return { ...actual, useTranslation: () => ({ t: (key: string) => `translated:${key}`, i18n: { language: 'pt_PT' } }) }
})
vi.mock('@/lib/api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/api')>()
  return { ...actual, api: { ...actual.api, get: vi.fn(() => Promise.resolve([])) } }
})
vi.mock('@/contexts/AuthContext', () => ({
  useAuth: () => ({ user: { id: 1, name: 'Ada', email: 'ada@example.com' }, logout: vi.fn() }),
}))
vi.mock('@/components/GlobalSearch', () => ({ GlobalSearch: () => null }))
vi.mock('@/components/PreferencesMenu', () => ({ PreferencesMenu: () => null }))
vi.mock('@/components/Footer', () => ({ Footer: () => null }))
vi.mock('@/components/Breadcrumbs', () => ({ Breadcrumbs: () => null }))

import { TopnavLayout } from './TopnavLayout'
import { componentRegistry } from '@/lib/componentRegistry'

function renderTopnav() {
  return render(
    <QueryClientProvider client={new QueryClient()}>
      <MemoryRouter>
        <TopnavLayout />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('TopnavLayout search button', () => {
  it('is named by the translated search label', () => {
    render(
      <QueryClientProvider client={new QueryClient()}>
        <MemoryRouter>
          <TopnavLayout />
        </MemoryRouter>
      </QueryClientProvider>,
    )

    expect(screen.getByRole('button', { name: 'translated:search_placeholder' })).toBeTruthy()
    expect(screen.queryByRole('button', { name: 'Search' })).toBeNull()
  })
})

describe('TopnavLayout skip link', () => {
  it('comes first in the Tab order and moves focus to the main landmark', async () => {
    const user = userEvent.setup()
    render(
      <QueryClientProvider client={new QueryClient()}>
        <MemoryRouter>
          <TopnavLayout />
        </MemoryRouter>
      </QueryClientProvider>,
    )

    await user.tab()
    expect(document.activeElement?.textContent).toBe('translated:skip_to_content')

    await user.keyboard('{Enter}')
    expect(document.activeElement?.id).toBe('martis-main')
  })
})

describe('TopnavLayout skip link to the navigation', () => {
  it('is the second Tab stop and moves focus to the navigation landmark', async () => {
    const user = userEvent.setup()
    renderTopnav()

    await user.tab()
    await user.tab()
    expect(document.activeElement?.textContent).toBe('translated:skip_to_navigation')

    await user.keyboard('{Enter}')
    expect(document.activeElement?.id).toBe('martis-navigation')
    expect(document.activeElement?.getAttribute('aria-label')).toBe('translated:main_navigation')
  })
})

describe('TopnavLayout top-bar slots', () => {
  afterEach(() => {
    componentRegistry.unregister('topbar:start')
    componentRegistry.unregister('topbar:end')
  })

  it('renders topbar:start after the brand and topbar:end before the user menu', () => {
    componentRegistry.register('topbar:start', () => <span>Tenant: Acme</span>)
    componentRegistry.register('topbar:end', () => <span>Status: online</span>)
    const { container } = renderTopnav()

    const follows = (a: Element, b: Element) => (a.compareDocumentPosition(b) & Node.DOCUMENT_POSITION_FOLLOWING) !== 0
    const start = screen.getByText('Tenant: Acme')
    const end = screen.getByText('Status: online')

    expect(follows(container.querySelector('.martis-topnav-brand')!, start)).toBe(true)
    expect(follows(start, container.querySelector('.martis-topnav-links')!)).toBe(true)
    expect(follows(screen.getByRole('button', { name: 'translated:search_placeholder' }), end)).toBe(true)
    expect(follows(end, container.querySelector('.martis-tb-user')!)).toBe(true)
  })
})
