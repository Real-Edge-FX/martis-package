import { afterEach, describe, expect, it, vi } from 'vitest'
import { act, fireEvent, render, renderHook, screen } from '@testing-library/react'
import { createMemoryRouter, Outlet, RouterProvider } from 'react-router'
import { ActionResponseModalProvider, useActionResponseModal, type ActionResponseModalProps } from './ActionResponseModalHost'
import { componentRegistry } from '@/lib/componentRegistry'

function TokenModal({ data, onClose }: ActionResponseModalProps) {
  return (
    <div role="dialog">
      <p>Token: {String(data.token)}</p>
      <button type="button" onClick={onClose}>Done</button>
      <button type="button" onClick={onClose}>Close again</button>
    </div>
  )
}

function BrokenModal(): never {
  throw new Error('modal component bug')
}

afterEach(() => {
  componentRegistry.unregister('generated-token')
  componentRegistry.unregister('broken-token')
  vi.restoreAllMocks()
})

/** Render the provider and hand back its `show`. */
function mountProvider() {
  let show: ReturnType<typeof useActionResponseModal> = null
  function Grab() {
    show = useActionResponseModal()
    return null
  }
  render(<ActionResponseModalProvider><Grab /></ActionResponseModalProvider>)

  return (component: string, data: Record<string, unknown>, onClose: () => void): boolean => {
    let shown = false
    act(() => {
      shown = show!(component, data, onClose)
    })
    return shown
  }
}

describe('ActionResponseModalProvider', () => {
  it('renders the registered component with the data, and calls onClose once', () => {
    componentRegistry.register('generated-token', TokenModal)
    const onClose = vi.fn()
    const show = mountProvider()

    expect(show('generated-token', { token: 's3cret' }, onClose)).toBe(true)
    expect(screen.getByText('Token: s3cret')).toBeTruthy()
    fireEvent.click(screen.getByRole('button', { name: 'Done' }))
    expect(onClose).toHaveBeenCalledTimes(1)
    expect(screen.queryByText('Token: s3cret')).toBeNull()
  })

  it('calls onClose once when the component closes twice in one go', () => {
    function DoubleClose({ onClose }: ActionResponseModalProps) {
      return <button type="button" onClick={() => { onClose(); onClose() }}>Close twice</button>
    }
    componentRegistry.register('generated-token', DoubleClose)
    const onClose = vi.fn()
    const show = mountProvider()

    show('generated-token', {}, onClose)
    fireEvent.click(screen.getByRole('button', { name: 'Close twice' }))

    expect(onClose).toHaveBeenCalledTimes(1)
  })

  it('closes the active modal, once, before it shows the next answer', () => {
    componentRegistry.register('generated-token', TokenModal)
    const first = vi.fn()
    const second = vi.fn()
    const show = mountProvider()

    show('generated-token', { token: 'one' }, first)
    show('generated-token', { token: 'two' }, second)

    expect(first).toHaveBeenCalledTimes(1)
    expect(second).not.toHaveBeenCalled()
    expect(screen.queryByText('Token: one')).toBeNull()
    expect(screen.getByText('Token: two')).toBeTruthy()

    fireEvent.click(screen.getByRole('button', { name: 'Done' }))
    expect(first).toHaveBeenCalledTimes(1)
    expect(second).toHaveBeenCalledTimes(1)
  })

  it('ignores a stale close from the modal the next answer replaced', () => {
    let staleClose: (() => void) | null = null
    function Capturing({ data, onClose }: ActionResponseModalProps) {
      if (data.token === 'one') staleClose = onClose
      return <p>Token: {String(data.token)}</p>
    }
    componentRegistry.register('generated-token', Capturing)
    const first = vi.fn()
    const second = vi.fn()
    const show = mountProvider()

    show('generated-token', { token: 'one' }, first)
    show('generated-token', { token: 'two' }, second)
    act(() => staleClose!())

    expect(first).toHaveBeenCalledTimes(1)
    expect(second).not.toHaveBeenCalled()
    expect(screen.getByText('Token: two')).toBeTruthy()
  })

  it('keeps the active modal when the next answer names no registered component', () => {
    componentRegistry.register('generated-token', TokenModal)
    const first = vi.fn()
    const show = mountProvider()

    show('generated-token', { token: 'one' }, first)

    expect(show('missing', {}, vi.fn())).toBe(false)
    expect(first).not.toHaveBeenCalled()
    expect(screen.getByText('Token: one')).toBeTruthy()
  })

  it('answers false for an unregistered component', () => {
    const { result } = renderHook(() => useActionResponseModal(), { wrapper: ActionResponseModalProvider })

    expect(result.current!('missing', {}, () => {})).toBe(false)
  })

  it('is null outside the provider', () => {
    const { result } = renderHook(() => useActionResponseModal())

    expect(result.current).toBeNull()
  })
})

describe('a response component that throws', () => {
  /**
   * The shell as Layout mounts it: the provider around the chrome and the
   * page, inside a data router with the Martis basename and no errorElement.
   */
  function renderShell(onClose: () => void) {
    function Page() {
      const show = useActionResponseModal()
      return (
        <>
          <button type="button" onClick={() => show!('broken-token', {}, onClose)}>Run broken</button>
          <button type="button" onClick={() => show!('generated-token', { token: 'after' }, vi.fn())}>Run working</button>
        </>
      )
    }
    const router = createMemoryRouter(
      [{
        path: '/',
        element: (
          <ActionResponseModalProvider>
            <nav>Sidebar</nav>
            <header>Topbar</header>
            <main><Outlet /></main>
          </ActionResponseModalProvider>
        ),
        children: [{ index: true, element: <Page /> }],
      }],
      { basename: '/martis', initialEntries: ['/martis'] },
    )
    return render(<RouterProvider router={router} />)
  }

  it('keeps the shell mounted, logs the registry key, and closes the modal once', () => {
    componentRegistry.register('broken-token', BrokenModal)
    const error = vi.spyOn(console, 'error').mockImplementation(() => {})
    const onClose = vi.fn()
    renderShell(onClose)

    fireEvent.click(screen.getByRole('button', { name: 'Run broken' }))

    expect(screen.queryByText(/Unexpected Application Error/)).toBeNull()
    expect(screen.getByText('Sidebar')).toBeTruthy()
    expect(screen.getByText('Topbar')).toBeTruthy()
    expect(screen.getByRole('button', { name: 'Run broken' })).toBeTruthy()
    expect(error).toHaveBeenCalledWith('[martis] action response component "broken-token" threw', expect.objectContaining({ message: 'modal component bug' }))
    expect(onClose).toHaveBeenCalledTimes(1)
  })

  it('shows the next answer after a component threw', () => {
    componentRegistry.register('broken-token', BrokenModal)
    componentRegistry.register('generated-token', TokenModal)
    vi.spyOn(console, 'error').mockImplementation(() => {})
    renderShell(vi.fn())

    fireEvent.click(screen.getByRole('button', { name: 'Run broken' }))
    fireEvent.click(screen.getByRole('button', { name: 'Run working' }))

    expect(screen.getByText('Token: after')).toBeTruthy()
  })
})
