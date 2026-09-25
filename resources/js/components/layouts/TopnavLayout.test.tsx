import { describe, it, expect, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
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
