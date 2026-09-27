import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, fireEvent, waitFor } from '@testing-library/react'
import { MemoryRouter } from 'react-router'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'

const mockNavigate = vi.fn()
const openExternal = vi.fn()

vi.mock('react-router', async () => {
  const actual = await vi.importActual<typeof import('react-router')>('react-router')
  return { ...actual, useNavigate: () => mockNavigate }
})

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, opts?: unknown) => (typeof opts === 'string' ? opts : key),
  }),
}))

vi.mock('@/lib/openExternal', () => ({ openExternal: (url: string) => openExternal(url) }))

const PALETTE = {
  resources: [],
  tools: [],
  actions: [],
  recent: [],
  commands: [
    { key: 'command:0', label: 'Open analyses', url: '/tools/analyses', external: false, icon: null, group: 'Reports' },
    { key: 'command:1', label: 'Status page', url: 'https://status.example.com', external: true, icon: 'globe', group: null },
  ],
}

vi.mock('@/lib/api', () => ({
  api: {
    get: vi.fn((url: string) => {
      if (url.includes('/api/command-palette')) return Promise.resolve(PALETTE)
      if (url.includes('/api/search')) return Promise.resolve({ results: [] })
      return Promise.resolve({})
    }),
  },
}))

import { GlobalSearch } from './GlobalSearch'

function renderPalette() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  render(
    <QueryClientProvider client={client}>
      <MemoryRouter>
        <GlobalSearch onClose={vi.fn()} />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('GlobalSearch: Commands section', () => {
  beforeEach(() => {
    mockNavigate.mockClear()
    openExternal.mockClear()
    window.sessionStorage.clear()
  })

  it('lists registered commands in their own section, with the group as the hint', async () => {
    renderPalette()

    await waitFor(() => expect(screen.getByText('Open analyses')).toBeTruthy())
    expect(screen.getByText('Commands')).toBeTruthy()
    expect(screen.getByText('Reports')).toBeTruthy()
  })

  it('navigates inside the panel for an internal command', async () => {
    renderPalette()

    await waitFor(() => expect(screen.getByText('Open analyses')).toBeTruthy())
    fireEvent.click(screen.getByText('Open analyses').closest('.martis-cmdk-item')!)

    expect(mockNavigate).toHaveBeenCalledWith('/tools/analyses')
    expect(openExternal).not.toHaveBeenCalled()
  })

  it('opens an external command in a new tab, as an external menu link does', async () => {
    renderPalette()

    await waitFor(() => expect(screen.getByText('Status page')).toBeTruthy())
    fireEvent.click(screen.getByText('Status page').closest('.martis-cmdk-item')!)

    expect(openExternal).toHaveBeenCalledWith('https://status.example.com')
    expect(mockNavigate).not.toHaveBeenCalled()
  })

  it('filters commands by the query', async () => {
    renderPalette()

    await waitFor(() => expect(screen.getByText('Open analyses')).toBeTruthy())
    fireEvent.change(screen.getByRole('textbox'), { target: { value: 'status' } })

    await waitFor(() => expect(screen.queryByText('Open analyses')).toBeNull())
    expect(screen.getByText('Status page')).toBeTruthy()
  })
})
