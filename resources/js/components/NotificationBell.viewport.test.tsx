import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'

/*
 * On a phone the bell sits away from the right edge, so the panel, aligned
 * to the bell's right edge, ran off the left edge of the screen. The bell now
 * measures itself on open and shifts the panel back inside the screen and
 * caps its height to the room under the top bar.
 */

vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (_key: string, fallback?: string) => fallback ?? _key, i18n: { language: 'en' } }),
}))

const notifications = vi.hoisted(() => ({ items: [] as Array<Record<string, unknown>> }))

vi.mock('@/lib/api', () => ({
  api: {
    get: (url: string) =>
      Promise.resolve(
        url.includes('unread-count')
          ? { unread: notifications.items.length }
          : { data: notifications.items, meta: { total: notifications.items.length, unread: notifications.items.length } },
      ),
    post: vi.fn(() => Promise.resolve({})),
    delete: vi.fn(() => Promise.resolve({})),
  },
}))

import { config } from '@/lib/config'
import { NotificationBell } from './NotificationBell'

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

beforeEach(() => {
  ;(config as { notifications?: Record<string, unknown> }).notifications = { enabled: true, poll_interval: 0 }
  // A 375x812 phone with the bell at x=196..230, y=10.5..44.5.
  vi.spyOn(window, 'innerHeight', 'get').mockReturnValue(812)
  vi.spyOn(HTMLElement.prototype, 'getBoundingClientRect').mockImplementation(function (this: HTMLElement) {
    return this.classList.contains('martis-notif-wrap')
      ? ({ left: 196, right: 230, top: 10.5, bottom: 44.5, width: 34, height: 34, x: 196, y: 10.5 } as DOMRect)
      : ({ left: 0, right: 0, top: 0, bottom: 0, width: 0, height: 0, x: 0, y: 0 } as DOMRect)
  })
  vi.spyOn(HTMLElement.prototype, 'offsetWidth', 'get').mockImplementation(function (this: HTMLElement) {
    return this.classList.contains('martis-notif-panel') ? 359 : 0
  })
})

afterEach(() => {
  ;(config as { notifications?: Record<string, unknown> }).notifications = undefined
  vi.restoreAllMocks()
})

describe('NotificationBell on a phone', () => {
  it('shifts the panel inside the screen and caps it to the room under the bell', () => {
    renderBell()
    fireEvent.click(screen.getByRole('button', { name: 'Notifications' }))

    const panel = screen.getByRole('dialog', { name: 'Notifications' })
    // 230 - 359 = -129 → 137px to the right puts the left edge at the 8px gutter.
    expect(panel.style.getPropertyValue('--martis-notif-shift')).toBe('-137px')
    // 812 - 44.5 - 8 (gap) - 8 (gutter).
    expect(panel.style.getPropertyValue('--martis-notif-room')).toBe('751px')
  })
})
