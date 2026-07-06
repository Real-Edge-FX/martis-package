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
    t: (key: string, opts?: unknown) => {
      let s = key
      if (typeof opts === 'string') s = opts
      else if (opts && typeof opts === 'object') {
        const o = opts as Record<string, unknown>
        s = (o.defaultValue as string) ?? key
        if ('count' in o) s = s.replace('{{count}}', String(o.count))
      }
      return s
    },
  }),
}))

// Eight resources — over the SECTION_LIMIT of 5.
const RESOURCES = Array.from({ length: 8 }, (_, i) => ({
  key: `resource:r${i + 1}`,
  uriKey: `r${i + 1}`,
  label: `Resource ${String(i + 1).padStart(2, '0')}`,
  icon: null,
  group: null,
  url: `/resources/r${i + 1}`,
}))

vi.mock('@/lib/api', () => ({
  api: {
    get: vi.fn((url: string) => {
      if (url.includes('/api/command-palette')) {
        return Promise.resolve({ resources: RESOURCES, tools: [], actions: [], recent: [] })
      }
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

describe('GlobalSearch — section limit / "show more" expander', () => {
  beforeEach(() => {
    mockNavigate.mockClear()
    window.sessionStorage.clear()
  })

  it('caps an oversized section to 5 rows and offers a "Show N more" expander', async () => {
    renderPalette()

    await waitFor(() => expect(screen.getByText('Resource 01')).toBeTruthy())

    // First 5 shown, the rest hidden behind the expander.
    expect(screen.getByText('Resource 05')).toBeTruthy()
    expect(screen.queryByText('Resource 06')).toBeNull()
    expect(screen.getByText('Show 3 more')).toBeTruthy()
  })

  it('reveals the rest of the section in place when the expander is clicked', async () => {
    renderPalette()

    await waitFor(() => expect(screen.getByText('Show 3 more')).toBeTruthy())

    fireEvent.click(screen.getByText('Show 3 more'))

    // All eight now visible; expander gone; no navigation happened.
    await waitFor(() => expect(screen.getByText('Resource 08')).toBeTruthy())
    expect(screen.getByText('Resource 06')).toBeTruthy()
    expect(screen.queryByText('Show 3 more')).toBeNull()
    expect(mockNavigate).not.toHaveBeenCalled()
  })
})
