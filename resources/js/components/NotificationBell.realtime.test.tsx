import { describe, it, expect, vi, beforeEach } from 'vitest'
import { act } from 'react'
import { render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (_key: string, fallback?: string) => fallback ?? _key,
  }),
}))

vi.mock('@/lib/api', () => ({
  api: {
    get: vi.fn((url: string) => {
      if (url.includes('unread-count')) {
        return Promise.resolve({ unread: 2 })
      }
      return Promise.resolve({ data: [], meta: { total: 0, unread: 2 } })
    }),
    post: vi.fn(() => Promise.resolve({})),
    delete: vi.fn(() => Promise.resolve({})),
  },
}))

import { NotificationBell } from './NotificationBell'
import { martisEventBus } from '@/lib/eventBus'

/**
 * Task 3 — pluggable real-time feed via martisEventBus.
 *
 * Proves the bell updates its unread badge the instant something emits
 * `martis:notification-received` on the shared event bus, without
 * waiting for the poll interval to elapse.
 */
describe('NotificationBell real-time updates (martis:notification-received)', () => {
  beforeEach(() => {
    martisEventBus.clear('martis:notification-received')
  })

  function renderBell() {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
    return render(
      <QueryClientProvider client={client}>
        <MemoryRouter>
          <NotificationBell />
        </MemoryRouter>
      </QueryClientProvider>,
    )
  }

  it('increments the unread badge when the event bus emits, without a poll tick', async () => {
    renderBell()

    // Initial unread count (2) loads from the mocked query.
    await waitFor(() => {
      expect(screen.getByLabelText('2 unread')).toBeTruthy()
    })

    act(() => {
      martisEventBus.emit('martis:notification-received', { id: 'x', title: 'Hi' })
    })

    await waitFor(() => {
      expect(screen.getByLabelText('3 unread')).toBeTruthy()
    })
  })
})
