import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { act, fireEvent, render, screen } from '@testing-library/react'

// Toast lifetime (v2.21.0): martis.toast.durations per severity, overridable
// per call with `life`, `null` = sticky, `dismissOnNavigate` for sticky ones.

vi.mock('react-i18next', () => ({ useTranslation: () => ({ t: (key: string) => key }) }))

import { config } from '@/lib/config'
import { ToastProvider, useToast } from './ToastContext'

let add: ReturnType<typeof useToast>['addToast'] = () => {}
function Grab() {
  add = useToast().addToast
  return null
}

type Listener = (state: { location: { key: string; pathname: string } }) => void
function fakeRouter() {
  let listener: Listener | null = null
  return {
    state: { location: { key: 'start', pathname: '/start' } },
    subscribe: (l: Listener) => {
      listener = l
      return () => {
        listener = null
      }
    },
    navigate: (pathname: string) => listener?.({ location: { key: pathname, pathname } }),
  }
}

function renderProvider(router?: ReturnType<typeof fakeRouter>) {
  render(
    <ToastProvider router={router}>
      <Grab />
    </ToastProvider>,
  )
}

// Advance in small steps so React flushes between the timer and the exit transition.
function tick(ms: number) {
  for (let done = 0; done < ms; done += 250) act(() => vi.advanceTimersByTime(250))
}

const cfg = config as unknown as { toast?: Record<string, unknown> }

beforeEach(() => {
  vi.useFakeTimers()
  cfg.toast = undefined
})
afterEach(() => {
  vi.useRealTimers()
  cfg.toast = undefined
})

describe('toast lifetime', () => {
  it('keeps the 5000 ms default without config', () => {
    renderProvider()
    act(() => add('info', 'Hello'))
    expect(screen.getByText('Hello')).toBeTruthy()
    tick(4000)
    expect(screen.queryByText('Hello')).toBeTruthy()
    tick(4500)
    expect(screen.queryByText('Hello')).toBeNull()
  })

  it('uses the configured duration of the severity', () => {
    cfg.toast = { durations: { error: 1000, success: 5000 } }
    renderProvider()
    act(() => add('error', 'Boom'))
    act(() => add('success', 'Fine'))
    tick(4000)
    expect(screen.queryByText('Boom')).toBeNull()
    expect(screen.queryByText('Fine')).toBeTruthy()
  })

  it('keeps a null duration until closed', () => {
    cfg.toast = { durations: { error: null } }
    renderProvider()
    act(() => add('error', 'Stuck'))
    tick(60000)
    expect(screen.queryByText('Stuck')).toBeTruthy()
    act(() => {
      fireEvent.click(screen.getByRole('button', { name: 'close' }))
    })
    tick(1000)
    expect(screen.queryByText('Stuck')).toBeNull()
  })

  it('lets the per-call life beat the config, including null and a number over sticky', () => {
    cfg.toast = { durations: { error: null, info: 1000 } }
    renderProvider()
    act(() => add('info', 'Sticky call', { life: null }))
    act(() => add('error', 'Short call', { life: 500 }))
    tick(3000)
    expect(screen.queryByText('Sticky call')).toBeTruthy()
    expect(screen.queryByText('Short call')).toBeNull()
  })

  it('keeps the 10000 ms link rule by default', () => {
    renderProvider()
    act(() => add('warning', 'Linked', { link: { href: 'https://example.com', label: 'Go' } }))
    tick(8000)
    expect(screen.queryByText('Linked')).toBeTruthy()
    tick(5500)
    expect(screen.queryByText('Linked')).toBeNull()
  })

  it('combines the link rule with the config: max(duration, 10000)', () => {
    cfg.toast = { durations: { warning: 20000 } }
    renderProvider()
    act(() => add('warning', 'Long link', { link: { href: 'https://example.com', label: 'Go' } }))
    tick(15000)
    expect(screen.queryByText('Long link')).toBeTruthy()
    tick(9000)
    expect(screen.queryByText('Long link')).toBeNull()
  })

  it('keeps a sticky config sticky even with a link', () => {
    cfg.toast = { durations: { warning: null } }
    renderProvider()
    act(() => add('warning', 'Sticky link', { link: { href: 'https://example.com', label: 'Go' } }))
    tick(60000)
    expect(screen.queryByText('Sticky link')).toBeTruthy()
  })
})

describe('sticky toast close control', () => {
  it('is a real, named, keyboard-reachable button', () => {
    cfg.toast = { durations: { error: null } }
    renderProvider()
    act(() => add('error', 'Stuck'))
    const close = screen.getByRole('button', { name: 'close' })
    expect(close.tagName).toBe('BUTTON')
    expect(close.getAttribute('type')).toBe('button')
    expect(close.getAttribute('tabindex')).not.toBe('-1')
    close.focus()
    expect(document.activeElement).toBe(close)
  })
})

describe('dismissOnNavigate', () => {
  it('removes a sticky toast flagged dismissOnNavigate on route change', () => {
    const router = fakeRouter()
    renderProvider(router)
    act(() => add('error', 'Goes away', { life: null, dismissOnNavigate: true }))
    act(() => router.navigate('/resources/users'))
    tick(1000)
    expect(screen.queryByText('Goes away')).toBeNull()
  })

  it('keeps a sticky toast without the flag across navigation', () => {
    const router = fakeRouter()
    renderProvider(router)
    act(() => add('error', 'Stays', { life: null }))
    act(() => router.navigate('/resources/users'))
    expect(screen.queryByText('Stays')).toBeTruthy()
  })

  it('does not dismiss on a state update that is not a navigation', () => {
    const router = fakeRouter()
    renderProvider(router)
    act(() => router.navigate('/a'))
    act(() => add('error', 'Stays', { life: null, dismissOnNavigate: true }))
    act(() => router.navigate('/a'))
    expect(screen.queryByText('Stays')).toBeTruthy()
  })
})
