import { describe, it, expect, vi } from 'vitest'
import { render, fireEvent, act, cleanup } from '@testing-library/react'
import { DrawerShell } from './DrawerShell'
import { KeyboardShortcutsHelp } from '@/components/KeyboardShortcutsHelp'
import { consumeSuppressFlag, getModalLockCount } from '@/lib/historyLock'

/*
 * A modal that closes pops its history sentinel and flags that pop, so a
 * drawer under it does not take it for a Back. With no drawer mounted
 * nothing consumed the flag: opening and closing the keyboard shortcuts
 * help on a page left it set, and the first Back in the next drawer was
 * swallowed (the drawer stayed open).
 */

async function wait(ms = 100) {
  await act(async () => { await new Promise((resolve) => setTimeout(resolve, ms)) })
}

describe('the history suppress flag', () => {
  it('is gone after a modal closes with no drawer mounted, so the next drawer closes on Back', async () => {
    render(<KeyboardShortcutsHelp />)
    fireEvent.keyDown(document, { key: '?', shiftKey: true })
    await wait()
    expect(getModalLockCount()).toBe(1)
    fireEvent.keyDown(document, { key: 'Escape' })
    await wait(300)

    // Nothing is left set once the modal's own pop has run.
    const stale = consumeSuppressFlag()
    expect(stale).toBe(false)
    cleanup()

    const onClose = vi.fn()
    render(<DrawerShell title="Drawer" onClose={onClose}><p>inside</p></DrawerShell>)
    await wait()
    act(() => { window.history.back() })
    await wait(400)

    expect(onClose).toHaveBeenCalledOnce()
  })
})
