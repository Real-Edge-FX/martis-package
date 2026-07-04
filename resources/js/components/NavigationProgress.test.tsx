import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { render, screen, act } from '@testing-library/react'
import { MemoryRouter, Routes, Route, useNavigate } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'

/*
 * NavigationProgress: router-shell top progress bar that signals in-flight
 * navigation. The router has no route `loader`s (data is fetched inside
 * page components via react-query), so `useNavigation()` from react-router
 * stays 'idle' and cannot detect these transitions. Instead the bar is
 * driven by:
 *   (a) `useLocation()` pathname changes -> START
 *   (b) `useIsFetching()` from react-query settling back to 0 -> COMPLETE
 *
 * `useIsFetching` is mocked as a partial of the real module so the test can
 * deterministically control the in-flight count instead of racing real
 * network timing. Fake timers drive the trickle/fade animation
 * deterministically (`vi.advanceTimersByTimeAsync`, following the pattern
 * used in SlugField.reserved-stability.test.tsx).
 */

let mockIsFetching = 0

vi.mock('@tanstack/react-query', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@tanstack/react-query')>()
  return {
    ...actual,
    useIsFetching: () => mockIsFetching,
  }
})

import { NavigationProgress } from './NavigationProgress'

let testNavigate!: (path: string) => void

function NavigateCapture() {
  const navigate = useNavigate()
  testNavigate = (path: string) => navigate(path)
  return null
}

function renderAt(path: string) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={client}>
      <MemoryRouter initialEntries={[path]}>
        <NavigateCapture />
        <Routes>
          <Route path="*" element={<NavigationProgress />} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

function getBar() {
  return screen.getByTestId('nav-progress')
}

describe('NavigationProgress', () => {
  beforeEach(() => {
    mockIsFetching = 0
    vi.useFakeTimers()
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  it('is inactive on initial mount (no navigation has happened yet)', () => {
    renderAt('/tools/alpha')
    const bar = getBar()
    expect(bar.getAttribute('data-active')).not.toBe('true')
  })

  it('becomes active and grows when a location change starts a fetch in flight', async () => {
    renderAt('/tools/alpha')

    // Fetch begins in-flight for the destination page, THEN navigation occurs
    // (mirrors react-query starting a fetch as the new route mounts).
    mockIsFetching = 1
    await act(async () => {
      testNavigate('/tools/beta')
    })

    expect(getBar().getAttribute('data-active')).toBe('true')

    // Advance the trickle animation timers.
    await act(async () => {
      await vi.advanceTimersByTimeAsync(500)
    })

    const width = Number(getBar().style.width.replace('%', ''))
    expect(width).toBeGreaterThan(0)
    expect(width).toBeLessThan(100)
  })

  it('completes to 100% then fades out and hides once fetching settles back to 0', async () => {
    renderAt('/tools/alpha')

    mockIsFetching = 1
    await act(async () => {
      testNavigate('/tools/beta')
    })
    expect(getBar().getAttribute('data-active')).toBe('true')

    await act(async () => {
      await vi.advanceTimersByTimeAsync(500)
    })

    // Fetch settles. The component re-reads `useIsFetching()` on every
    // render; its own trickle timer ticks every 200ms and causes the next
    // render, so advancing past one tick is enough for the completion
    // effect to observe the new value.
    mockIsFetching = 0
    await act(async () => {
      await vi.advanceTimersByTimeAsync(200)
    })

    expect(getBar().style.width).toBe('100%')

    // Fade-out timer. Split into two advances: a timer *scheduled* partway
    // through one `advanceTimersByTimeAsync` window is not guaranteed to be
    // picked up within that same call, so give it a fresh window to fire in.
    await act(async () => {
      await vi.advanceTimersByTimeAsync(500)
    })
    await act(async () => {
      await vi.advanceTimersByTimeAsync(500)
    })

    expect(getBar().getAttribute('data-active')).not.toBe('true')
    expect(getBar().style.width).toBe('0%')
  })

  it('restarts cleanly if a new pathname change arrives mid-run', async () => {
    renderAt('/tools/alpha')

    mockIsFetching = 1
    await act(async () => {
      testNavigate('/tools/beta')
    })
    expect(getBar().getAttribute('data-active')).toBe('true')

    await act(async () => {
      await vi.advanceTimersByTimeAsync(200)
    })

    // A second navigation arrives before the first completed.
    await act(async () => {
      testNavigate('/tools/gamma')
    })
    expect(getBar().getAttribute('data-active')).toBe('true')

    // Still in-flight (not faded out), and eventually completes when fetching drops to 0.
    mockIsFetching = 0
    await act(async () => {
      await vi.advanceTimersByTimeAsync(200)
    })

    expect(getBar().style.width).toBe('100%')
  })
})
