import { describe, expect, it, vi } from 'vitest'
import { act, render } from '@testing-library/react'

// The gate modal opens when the API client raises `martis:locked` (a data
// endpoint answered 403 with a lock payload, v2.4.0).

import { GateProvider, useGate } from './GateContext'
import { LOCKED_EVENT } from '@/lib/lockEvent'
import type { GateLock } from '@/types'

const LOCK: GateLock = { reason: 'plan:pro', modal: { title: 'Pro feature' } }

function Probe() {
  const { isOpen, lock } = useGate()
  return <div data-testid="gate">{isOpen ? (lock?.modal?.title ?? 'open') : 'closed'}</div>
}

describe('GateProvider on the locked event', () => {
  it('opens the gate with the lock the event carries', () => {
    const view = render(<GateProvider><Probe /></GateProvider>)
    expect(view.getByTestId('gate').textContent).toBe('closed')

    act(() => {
      window.dispatchEvent(new CustomEvent(LOCKED_EVENT, { detail: LOCK }))
    })

    expect(view.getByTestId('gate').textContent).toBe('Pro feature')
  })

  it('stops listening once unmounted', () => {
    const add = vi.spyOn(window, 'addEventListener')
    const remove = vi.spyOn(window, 'removeEventListener')
    const view = render(<GateProvider><Probe /></GateProvider>)
    const added = add.mock.calls.filter(([type]) => type === LOCKED_EVENT).map(([, handler]) => handler)
    expect(added).toHaveLength(1)

    view.unmount()

    const removed = remove.mock.calls.filter(([type]) => type === LOCKED_EVENT).map(([, handler]) => handler)
    expect(removed).toEqual(added)
    add.mockRestore()
    remove.mockRestore()
  })
})
