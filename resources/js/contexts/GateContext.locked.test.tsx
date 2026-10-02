import { describe, expect, it } from 'vitest'
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
    const view = render(<GateProvider><Probe /></GateProvider>)
    view.unmount()

    expect(() => window.dispatchEvent(new CustomEvent(LOCKED_EVENT, { detail: LOCK }))).not.toThrow()
  })
})
