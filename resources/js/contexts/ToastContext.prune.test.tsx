import { beforeEach, describe, expect, it, vi } from 'vitest'
import { act, render } from '@testing-library/react'
import { forwardRef, useImperativeHandle } from 'react'

// The set of toasts a navigation dismisses is pruned when a toast is removed
// (v2.21.0): PrimeReact's Toast is replaced by a stub that records `show`,
// `remove` and the `onRemove` callback the provider passes.

vi.mock('react-i18next', () => ({ useTranslation: () => ({ t: (key: string) => key }) }))

const show = vi.fn()
const remove = vi.fn()
let onRemove: ((message: unknown) => void) | undefined

vi.mock('primereact/toast', () => ({
  Toast: forwardRef(function FakeToast(props: { onRemove?: (message: unknown) => void }, ref) {
    onRemove = props.onRemove
    useImperativeHandle(ref, () => ({ show, remove, clear: vi.fn(), replace: vi.fn(), getElement: vi.fn() }))
    return null
  }),
}))

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

beforeEach(() => {
  show.mockClear()
  remove.mockClear()
  onRemove = undefined
})

describe('dismissOnNavigate bookkeeping', () => {
  it('forgets a toast once it is removed, so a later navigation does not touch it again', () => {
    const router = fakeRouter()
    render(
      <ToastProvider router={router}>
        <Grab />
      </ToastProvider>,
    )
    act(() => add('error', 'Closed by the user', { life: null, dismissOnNavigate: true }))
    act(() => add('error', 'Still open', { life: null, dismissOnNavigate: true }))
    const [closed, open] = show.mock.calls.map((c) => c[0])

    // The user closes the first one: PrimeReact reports it through onRemove.
    act(() => onRemove?.(closed))
    act(() => router.navigate('/resources/users'))

    expect(remove).toHaveBeenCalledTimes(1)
    expect(remove).toHaveBeenCalledWith(open)
  })

  it('still removes every toast that is open on navigation', () => {
    const router = fakeRouter()
    render(
      <ToastProvider router={router}>
        <Grab />
      </ToastProvider>,
    )
    act(() => add('error', 'A', { life: null, dismissOnNavigate: true }))
    act(() => add('error', 'B', { life: null, dismissOnNavigate: true }))
    act(() => router.navigate('/next'))

    expect(remove).toHaveBeenCalledTimes(2)
  })
})
