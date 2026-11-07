import { describe, it, expect, vi, afterEach } from 'vitest'
import { render, act, cleanup, fireEvent } from '@testing-library/react'
import { useState } from 'react'
import { DrawerShell } from './DrawerShell'

/*
 * DrawerShell pushes a history sentinel on mount so Back closes the drawer
 * first (v2.18.0 fixes two gaps found with records opened over the index):
 *
 * - A drawer swapping in for another one (detail → update and back)
 *   stacked one more sentinel per swap, so Back, or a host closing the
 *   drawer by stepping back, stopped one entry short of the page.
 * - A pop that left the drawer's page (the host stepping back to the
 *   index) was taken for a Back press: the drawer pushed its sentinel
 *   again, on the index URL.
 */

async function wait(ms = 50) {
  await act(async () => { await new Promise((resolve) => setTimeout(resolve, ms)) })
}

afterEach(() => {
  cleanup()
})

function Swapper() {
  const [editing, setEditing] = useState(false)
  return editing
    ? <DrawerShell key="update" title="Update" onClose={() => {}}><button type="button" onClick={() => setEditing(false)}>To detail</button></DrawerShell>
    : <DrawerShell key="detail" title="Detail" onClose={() => {}}><button type="button" onClick={() => setEditing(true)}>To update</button></DrawerShell>
}

describe('DrawerShell history sentinel', () => {
  it('keeps one sentinel across detail → update → detail swaps', async () => {
    const before = window.history.length
    const { getByText } = render(<Swapper />)
    await wait()
    expect(window.history.length).toBe(before + 1)

    fireEvent.click(getByText('To update'))
    await wait()
    fireEvent.click(getByText('To detail'))
    await wait()

    expect(window.history.length).toBe(before + 1)
    expect((window.history.state as { martisDrawer?: boolean } | null)?.martisDrawer).toBe(true)
  })

  it('leaves a pop to another page to the host', async () => {
    window.history.pushState({ page: 'index' }, '', '/resources/conversations')
    window.history.pushState({ page: 'record' }, '', '/resources/conversations/7')
    const onClose = vi.fn()
    render(<DrawerShell title="Detail" onClose={onClose}><p>inside</p></DrawerShell>)
    await wait()

    // The host steps back past the sentinel and the record entry.
    act(() => { window.history.go(-2) })
    await wait(300)

    expect(window.location.pathname).toBe('/resources/conversations')
    expect(window.history.state).toEqual({ page: 'index' })
    expect(onClose).not.toHaveBeenCalled()
  })
})
