import { afterEach, beforeEach, describe, expect, it, vi, type MockInstance } from 'vitest'
import { render, screen, fireEvent, waitFor } from '@testing-library/react'
import { MemoryRouter } from 'react-router'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'

/*
 * `MenuItem::externalLink()` may be a `mailto:` / `tel:` link (docs/menus.md):
 * the sidebar renders it, and the command palette entry for the same item
 * must open it too. `openExternal()` is the real one here (no mock), so the
 * test pins what reaches `window.open`.
 */

vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (key: string, opts?: unknown) => (typeof opts === 'string' ? opts : key) }),
}))

const PALETTE = {
  resources: [],
  tools: [],
  actions: [],
  recent: [],
  commands: [
    { key: 'command:0', label: 'Email support', url: 'mailto:support@example.com', external: true, icon: null, group: null },
    { key: 'command:1', label: 'Call support', url: 'tel:+351210000000', external: true, icon: null, group: null },
    { key: 'command:2', label: 'Run script', url: 'javascript:alert(1)', external: true, icon: null, group: null },
    { key: 'command:3', label: 'Open file', url: 'file:///etc/passwd', external: true, icon: null, group: null },
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

let openSpy: MockInstance<typeof window.open>
let errorSpy: MockInstance<typeof console.error>

beforeEach(() => {
  window.sessionStorage.clear()
  openSpy = vi.spyOn(window, 'open').mockImplementation(() => null)
  errorSpy = vi.spyOn(console, 'error').mockImplementation(() => {})
})

afterEach(() => {
  vi.restoreAllMocks()
})

async function clickCommand(label: string) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  render(
    <QueryClientProvider client={client}>
      <MemoryRouter>
        <GlobalSearch onClose={vi.fn()} />
      </MemoryRouter>
    </QueryClientProvider>,
  )
  await waitFor(() => expect(screen.getByText(label)).toBeTruthy())
  fireEvent.click(screen.getByText(label).closest('.martis-cmdk-item')!)
}

describe('GlobalSearch external command with a contact link', () => {
  it.each([
    ['Email support', 'mailto:support@example.com'],
    ['Call support', 'tel:+351210000000'],
  ])('opens %s', async (label, url) => {
    await clickCommand(label)

    expect(openSpy).toHaveBeenCalledWith(url, '_blank', 'noopener,noreferrer')
    expect(errorSpy).not.toHaveBeenCalled()
  })

  it.each(['Run script', 'Open file'])('still refuses %s', async (label) => {
    await clickCommand(label)

    expect(openSpy).not.toHaveBeenCalled()
    expect(errorSpy).toHaveBeenCalledTimes(1)
  })
})
