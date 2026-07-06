import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, fireEvent, waitFor } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'

const mockNavigate = vi.fn()

vi.mock('react-router-dom', async () => {
  const actual = await vi.importActual<typeof import('react-router-dom')>('react-router-dom')
  return { ...actual, useNavigate: () => mockNavigate }
})

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, opts?: unknown) => (typeof opts === 'string' ? opts : key),
  }),
}))

// Palette payload includes a Tools section (the v1.27 enhancement).
const PALETTE = {
  resources: [],
  tools: [
    { key: 'tool:standards', uriKey: 'standards', label: 'Standards', icon: 'book', group: 'Knowledge', url: '/tools/standards' },
  ],
  actions: [],
  recent: [],
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

describe('GlobalSearch — Tools section', () => {
  beforeEach(() => {
    mockNavigate.mockClear()
    window.sessionStorage.clear()
  })

  it('renders a registered Tool as a clickable row that navigates to /tools/{uriKey}', async () => {
    renderPalette()

    // No query needed — resources/tools show on open.
    await waitFor(() => expect(screen.getByText('Standards')).toBeTruthy())

    const row = screen.getByText('Standards').closest('.martis-cmdk-item')
    expect(row).not.toBeNull()
    expect(row!.tagName).toBe('BUTTON')

    fireEvent.click(row!)
    expect(mockNavigate).toHaveBeenCalledWith('/tools/standards')
  })
})
