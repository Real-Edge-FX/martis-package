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

let unreadCallCount = 0

vi.mock('@/lib/api', () => ({
  api: {
    get: vi.fn((url: string) => {
      if (url.includes('unread-count')) {
        unreadCallCount += 1
        // First resolution: 3 unread. Any subsequent fetch (triggered by
        // a reconcile invalidation) resolves to 0 — simulating a
        // read/read-all/delete that happened in another session.
        return Promise.resolve({ unread: unreadCallCount === 1 ? 3 : 0 })
      }
      return Promise.resolve({ data: [], meta: { total: 0, unread: 0 } })
    }),
    post: vi.fn(() => Promise.resolve({})),
    delete: vi.fn(() => Promise.resolve({})),
  },
}))

import { NotificationBell } from './NotificationBell'
import { martisEventBus } from '@/lib/eventBus'

/**
 * Reconcile event — `martis:notifications-changed`.
 *
 * Proves the bell re-fetches (invalidates) its unread count + list query
 * the instant something emits `martis:notifications-changed` on the
 * shared event bus, without waiting for the poll interval to elapse.
 * Unlike `martis:notification-received` (which optimistically increments),
 * this event carries no payload and simply forces a reconcile — the
 * count can go DOWN (e.g. a read/read-all/delete happened elsewhere).
 */
describe('NotificationBell reconcile (martis:notifications-changed)', () => {
  beforeEach(() => {
    unreadCallCount = 0
    martisEventBus.clear('martis:notifications-changed')
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

  it('reconciles the unread badge down to the fresh value when the event bus emits, without a poll tick', async () => {
    renderBell()

    // Initial unread count (3) loads from the mocked query.
    await waitFor(() => {
      expect(screen.getByLabelText('3 unread')).toBeTruthy()
    })

    act(() => {
      martisEventBus.emit('martis:notifications-changed', {})
    })

    // Reconciled value (0) means the unread badge disappears entirely.
    await waitFor(() => {
      expect(screen.queryByLabelText('3 unread')).toBeNull()
      expect(screen.queryByText(/unread/)).toBeNull()
    })
  })
})
