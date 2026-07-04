import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, waitFor, act } from '@testing-library/react'
import { MemoryRouter, Routes, Route, useNavigate } from 'react-router-dom'

/*
 * ToolPage bug: switching sidebar Tools reused the previous tool because
 * the component never reset `descriptor`/`lockedPayload` on `uriKey`
 * change and the route had no remount `key` — the OLD tool stayed
 * mounted, its effects kept firing (including a stale `setSearchParams`
 * from the outgoing tool). Fix: wrap the page in a thin component keyed
 * on `uriKey` so React unmounts/remounts the inner component whenever
 * the sidebar tool changes, and abort the in-flight fetch on teardown.
 *
 * This test proves the remount by asserting the loader appears and the
 * previous tool's marker is gone when navigating from one uriKey to
 * another, and that `api.get` is called once per uriKey.
 */

const apiGetMock = vi.fn()

vi.mock('@/lib/api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/api')>()
  return {
    ...actual,
    api: {
      ...actual.api,
      get: (...args: unknown[]) => apiGetMock(...args),
    },
  }
})

import { componentRegistry } from '@/lib/componentRegistry'
import { ToastProvider } from '@/contexts/ToastContext'
import { ToolPage } from './ToolPage'

function AlphaTool() {
  return <div>TOOL-ALPHA</div>
}

function BetaTool() {
  return <div>TOOL-BETA</div>
}

function descriptorFor(uriKey: string, component: string) {
  return {
    type: 'tool' as const,
    name: uriKey,
    breadcrumb: null,
    uriKey,
    icon: null,
    component,
    menuSection: null,
    meta: {},
  }
}

// Captures an imperative `navigate` closure in a module-scoped variable so
// the test can drive real client-side navigation within a single
// MemoryRouter instance. Using `rerender` with a brand-new
// `<MemoryRouter initialEntries=...>` does NOT work here — MemoryRouter
// only reads `initialEntries` on its first mount, so a second
// `initialEntries` prop is silently ignored and the router's history never
// actually changes path. Driving a real `navigate()` call (as the
// sidebar's <Link>/useNavigate would) is what actually exercises the
// `uriKey` param change this fix targets.
let testNavigate!: (path: string) => void

function NavigateCapture() {
  const navigate = useNavigate()
  testNavigate = (path: string) => navigate(path)
  return null
}

function renderAt(path: string) {
  return render(
    <ToastProvider>
      <MemoryRouter initialEntries={[path]}>
        <NavigateCapture />
        <Routes>
          <Route path="/tools/:uriKey" element={<ToolPage />} />
        </Routes>
      </MemoryRouter>
    </ToastProvider>,
  )
}

describe('ToolPage remount on uriKey change', () => {
  beforeEach(() => {
    apiGetMock.mockReset()
    componentRegistry.register('tool:alpha', AlphaTool)
    componentRegistry.register('tool:beta', BetaTool)
  })

  it('remounts the inner page (fresh loader + fresh fetch) when uriKey changes', async () => {
    let resolveAlpha!: (value: unknown) => void
    let resolveBeta!: (value: unknown) => void

    apiGetMock.mockImplementation((path: string) => {
      if (path === '/api/tools/alpha') {
        return new Promise((resolve) => {
          resolveAlpha = resolve
        })
      }
      if (path === '/api/tools/beta') {
        return new Promise((resolve) => {
          resolveBeta = resolve
        })
      }
      return Promise.reject(new Error(`unexpected path: ${path}`))
    })

    renderAt('/tools/alpha')

    // Loading state before the alpha fetch resolves.
    expect(screen.getByText(/loading/i)).toBeTruthy()

    resolveAlpha(descriptorFor('alpha', 'tool:alpha'))

    await waitFor(() => {
      expect(screen.getByText('TOOL-ALPHA')).toBeTruthy()
    })

    expect(apiGetMock).toHaveBeenCalledTimes(1)
    expect(apiGetMock.mock.calls[0]?.[0]).toBe('/api/tools/alpha')

    // Navigate to a different tool, exactly as clicking a different
    // sidebar Tool link would (route param change, same mounted tree).
    act(() => {
      testNavigate('/tools/beta')
    })

    // The remount must show the loader again — proving the OLD
    // descriptor was thrown away instead of being reused.
    await waitFor(() => {
      expect(screen.getByText(/loading/i)).toBeTruthy()
    })
    expect(screen.queryByText('TOOL-ALPHA')).toBeNull()

    resolveBeta(descriptorFor('beta', 'tool:beta'))

    await waitFor(() => {
      expect(screen.getByText('TOOL-BETA')).toBeTruthy()
    })
    expect(screen.queryByText('TOOL-ALPHA')).toBeNull()

    expect(apiGetMock).toHaveBeenCalledTimes(2)
    expect(apiGetMock.mock.calls[1]?.[0]).toBe('/api/tools/beta')
  })

  it('still supports the prefilled drawer path (no fetch, renders immediately)', async () => {
    const descriptor = descriptorFor('alpha', 'tool:alpha')

    render(
      <ToastProvider>
        <MemoryRouter initialEntries={['/tools/alpha']}>
          <Routes>
            <Route path="/tools/:uriKey" element={<ToolPage descriptor={descriptor} />} />
          </Routes>
        </MemoryRouter>
      </ToastProvider>,
    )

    expect(screen.getByText('TOOL-ALPHA')).toBeTruthy()
    expect(apiGetMock).not.toHaveBeenCalled()
  })
})
