import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, fireEvent, waitFor } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'

const mockNavigate = vi.fn()

vi.mock('react-router-dom', async () => {
  const actual = await vi.importActual<typeof import('react-router-dom')>('react-router-dom')
  return { ...actual, useNavigate: () => mockNavigate }
})

// Minimal i18n: honour the { defaultValue } / string fallbacks and
// interpolate {{count}} / {{resource}} the way i18next would.
vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, opts?: unknown) => {
      let s = key
      if (typeof opts === 'string') s = opts
      else if (opts && typeof opts === 'object') {
        const o = opts as Record<string, unknown>
        s = (o.defaultValue as string) ?? key
        if ('count' in o) s = s.replace('{{count}}', String(o.count))
        if ('resource' in o) s = s.replace('{{resource}}', String(o.resource))
      }
      return s
    },
  }),
}))

// Two record groups: a non-routable one (viewAllUrl null → static count)
// and a routable one (viewAllUrl present → clickable link). Both overflow
// so the "view all" row is emitted (total > items.length).
const SEARCH_RESPONSE = {
  results: [
    {
      resource: 'normas',
      label: 'Normas',
      items: [{ id: 1, title: 'Move Test', subtitle: null, image: null, url: '/tools/normas?id=1' }],
      total: 104,
      viewAllUrl: null,
    },
    {
      resource: 'users',
      label: 'Users',
      items: [{ id: 1, title: 'Alice', subtitle: null, image: null, url: '/resources/users/1' }],
      total: 50,
      viewAllUrl: '/resources/users?search=Move',
    },
  ],
}

vi.mock('@/lib/api', () => ({
  api: {
    get: vi.fn((url: string) => {
      if (url.includes('/api/command-palette')) {
        return Promise.resolve({ resources: [], actions: [], recent: [] })
      }
      if (url.includes('/api/search')) {
        return Promise.resolve(SEARCH_RESPONSE)
      }
      return Promise.resolve({})
    }),
  },
}))

import { GlobalSearch } from './GlobalSearch'

function renderPalette() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  const onClose = vi.fn()
  render(
    <QueryClientProvider client={client}>
      <MemoryRouter>
        <GlobalSearch onClose={onClose} />
      </MemoryRouter>
    </QueryClientProvider>,
  )
  return { onClose }
}

async function typeQueryAndWaitForResults() {
  const input = screen.getByRole('textbox')
  fireEvent.change(input, { target: { value: 'Move' } })
  // Real 300ms debounce → query fires → results render.
  await waitFor(() => expect(screen.getByText('View all 104 in Normas')).toBeTruthy(), {
    timeout: 2000,
  })
}

describe('GlobalSearch — "view all" affordance', () => {
  beforeEach(() => {
    mockNavigate.mockClear()
    window.sessionStorage.clear()
  })

  it('renders a non-routable group\'s count as a static, non-clickable label', async () => {
    renderPalette()
    await typeQueryAndWaitForResults()

    const row = screen.getByText('View all 104 in Normas').closest('.martis-cmdk-item')
    expect(row).not.toBeNull()
    // Not a button, and flagged static so CSS strips the affordance.
    expect(row!.tagName).toBe('DIV')
    expect(row!.classList.contains('is-static')).toBe(true)

    // Clicking the dead row does nothing — no navigation.
    fireEvent.click(row!)
    expect(mockNavigate).not.toHaveBeenCalled()
  })

  it('renders a routable group\'s count as a clickable link that navigates', async () => {
    renderPalette()
    await typeQueryAndWaitForResults()

    const row = screen.getByText('View all 50 in Users').closest('.martis-cmdk-item')
    expect(row).not.toBeNull()
    expect(row!.tagName).toBe('BUTTON')

    fireEvent.click(row!)
    expect(mockNavigate).toHaveBeenCalledWith('/resources/users?search=Move')
  })
})
