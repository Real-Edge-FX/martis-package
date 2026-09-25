import { describe, it, expect, vi, beforeAll, afterAll } from 'vitest'
import { useRef, useState, type ReactNode } from 'react'
import { render, screen, fireEvent, act } from '@testing-library/react'
import { MemoryRouter } from 'react-router'
import { OverlayPanel } from 'primereact/overlaypanel'
import { DrawerShell } from './DrawerShell'
import { DeleteModal } from '@/components/DeleteModal'
import { KeyboardShortcutsHelp } from '@/components/KeyboardShortcutsHelp'
import { ActionDropdown } from '@/components/Actions/ActionDropdown'
import type { ActionMeta } from '@/components/Actions/ActionModal'

/*
 * Escape as a user presses it. The browser runs a microtask checkpoint
 * after each listener, and React 18 applies a discrete update, and its
 * effect cleanups, in a microtask: once a layer's own listener has run,
 * its history lock is back to 0 and it has left the layer registry.
 * fireEvent dispatches every listener in one go, so it hid that a drawer
 * whose listener ran after the layer's closed too. It happened with the
 * keyboard shortcuts help (a capture listener) and with any modal once the
 * drawer's owner re-rendered with it open (an inline onClose registers the
 * drawer's listener again, last). `nativeEscape()` runs the document's
 * keydown listeners one at a time, capture first, and drains the
 * microtasks between them (the review's helper).
 */

type Rec = { id: number; fn: EventListenerOrEventListenerObject; capture: boolean; wrapped: EventListener }
const recs: Rec[] = []
let seq = 0
let only: number | null = null
const realAdd = document.addEventListener
const realRemove = document.removeEventListener

function isCapture(opts: unknown): boolean {
  return opts === true || (typeof opts === 'object' && opts !== null && (opts as { capture?: boolean }).capture === true)
}

beforeAll(() => {
  document.addEventListener = function (this: Document, type: string, fn: EventListenerOrEventListenerObject, opts?: boolean | AddEventListenerOptions) {
    if (type !== 'keydown' || fn == null) return realAdd.call(this, type, fn, opts)
    const id = ++seq
    const wrapped: EventListener = (e) => {
      if (only !== null && only !== id) return
      if (typeof fn === 'function') fn.call(this, e)
      else fn.handleEvent(e)
    }
    recs.push({ id, fn, capture: isCapture(opts), wrapped })
    return realAdd.call(this, type, wrapped, opts)
  } as typeof document.addEventListener
  document.removeEventListener = function (this: Document, type: string, fn: EventListenerOrEventListenerObject, opts?: boolean | EventListenerOptions) {
    if (type !== 'keydown') return realRemove.call(this, type, fn, opts)
    const idx = recs.findIndex((r) => r.fn === fn && r.capture === isCapture(opts))
    if (idx < 0) return realRemove.call(this, type, fn, opts)
    const [r] = recs.splice(idx, 1)
    return realRemove.call(this, type, r.wrapped, opts)
  } as typeof document.removeEventListener
  ;(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = false
})

afterAll(() => {
  document.addEventListener = realAdd
  document.removeEventListener = realRemove
  ;(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true
})

async function drainMicrotasks() {
  for (let i = 0; i < 10; i++) await Promise.resolve()
}

/** One Escape key press: each document listener on its own, capture first, microtasks between. */
async function nativeEscape(): Promise<void> {
  const capture = recs.filter((r) => r.capture).map((r) => r.id)
  const bubble = recs.filter((r) => !r.capture).map((r) => r.id)
  const ev = new KeyboardEvent('keydown', { key: 'Escape', code: 'Escape', bubbles: true, cancelable: true })
  let stopBubble = false
  let stopAll = false
  const sp = ev.stopPropagation.bind(ev)
  const sip = ev.stopImmediatePropagation.bind(ev)
  let phase: 'capture' | 'bubble' = 'capture'
  ev.stopPropagation = () => { if (phase === 'capture') stopBubble = true; sp() }
  ev.stopImmediatePropagation = () => { stopAll = true; sip() }
  for (const [list, ph] of [[capture, 'capture'], [bubble, 'bubble']] as const) {
    phase = ph
    if (ph === 'bubble' && stopBubble) break
    for (const id of list) {
      if (stopAll) break
      if (!recs.some((r) => r.id === id)) continue
      only = id
      document.body.dispatchEvent(ev)
      only = null
      await drainMicrotasks()
    }
  }
}

async function settle(ms = 400) {
  await new Promise((r) => setTimeout(r, ms))
}

const action: ActionMeta = {
  uriKey: 'archive-posts', name: 'Archive posts', icon: null, showIcon: true, iconColor: null,
  group: null, destructive: false, showOnIndex: true, showOnDetail: true, showInline: true,
  executionMode: 'bulk', standalone: false, sole: false, queued: false, withConfirmation: false,
  confirmText: null, confirmButtonText: 'Run', cancelButtonText: null, modalSize: 'md',
  supportsDryRun: false, customComponent: null, customComponentProps: {}, logEvents: true,
  isPivotAction: false, pivotLabel: null,
}

function DeleteLayer() {
  const [open, setOpen] = useState(false)
  return (
    <>
      <button type="button" onClick={() => setOpen(true)}>Open delete</button>
      <DeleteModal open={open} resourceLabel="Profile" isSoftDelete={false} onConfirm={async () => setOpen(false)} onCancel={() => setOpen(false)} />
    </>
  )
}

function PanelLayer() {
  const ref = useRef<OverlayPanel>(null)
  return (
    <>
      <button type="button" onClick={(e) => ref.current?.toggle(e)}>Open panel</button>
      <OverlayPanel ref={ref}><p>Panel content</p></OverlayPanel>
    </>
  )
}

/** A drawer whose owner can re-render, with an inline onClose, as ResourceDetail passes one. */
function Owner({ onDrawerClose, children }: { onDrawerClose: () => void; children: ReactNode }) {
  const [, setTick] = useState(0)
  return (
    <MemoryRouter>
      <button type="button" onClick={() => setTick((t) => t + 1)}>Re-render owner</button>
      <DrawerShell title="Drawer" onClose={() => onDrawerClose()}>{children}</DrawerShell>
    </MemoryRouter>
  )
}

async function reRenderOwner() {
  fireEvent.click(screen.getByRole('button', { name: 'Re-render owner', hidden: true }))
  await settle(50)
}

describe('a real Escape key press over a drawer', () => {
  it.each([false, true])('closes the delete confirmation only (owner re-rendered: %s)', async (reRender) => {
    const onDrawerClose = vi.fn()
    render(<Owner onDrawerClose={onDrawerClose}><DeleteLayer /></Owner>)
    await settle(50)
    fireEvent.click(screen.getByRole('button', { name: 'Open delete' }))
    await settle(50)
    if (reRender) await reRenderOwner()

    await nativeEscape()
    await settle()

    expect(screen.queryByRole('dialog')).toBeNull()
    expect(onDrawerClose).not.toHaveBeenCalled()
  })

  it.each([false, true])('closes the keyboard shortcuts help only (owner re-rendered: %s)', async (reRender) => {
    const onDrawerClose = vi.fn()
    render(<Owner onDrawerClose={onDrawerClose}><KeyboardShortcutsHelp /></Owner>)
    await settle(50)
    await act(async () => { fireEvent.keyDown(document, { key: '?', shiftKey: true }) })
    await settle(50)
    expect(screen.queryByRole('dialog')).not.toBeNull()
    if (reRender) await reRenderOwner()

    await nativeEscape()
    await settle()

    expect(screen.queryByRole('dialog')).toBeNull()
    expect(onDrawerClose).not.toHaveBeenCalled()
  })

  it.each([false, true])('closes the actions menu only (owner re-rendered: %s)', async (reRender) => {
    const onDrawerClose = vi.fn()
    render(<Owner onDrawerClose={onDrawerClose}><ActionDropdown actions={[action]} onSelect={() => {}} label="Actions" /></Owner>)
    await settle(50)
    fireEvent.click(screen.getByRole('button', { name: /Actions/ }))
    await settle(50)
    expect(screen.queryByText('Archive posts')).not.toBeNull()
    if (reRender) await reRenderOwner()

    await nativeEscape()
    await settle()

    expect(screen.queryByText('Archive posts')).toBeNull()
    expect(onDrawerClose).not.toHaveBeenCalled()
  })

  it.each([false, true])('closes a PrimeReact overlay that does not mark its Escape handled (owner re-rendered: %s)', async (reRender) => {
    const onDrawerClose = vi.fn()
    render(<Owner onDrawerClose={onDrawerClose}><PanelLayer /></Owner>)
    await settle(50)
    fireEvent.click(screen.getByRole('button', { name: 'Open panel' }))
    await settle(50)
    expect(screen.queryByText('Panel content')).not.toBeNull()
    if (reRender) await reRenderOwner()

    await nativeEscape()
    await settle()

    expect(screen.queryByText('Panel content')).toBeNull()
    expect(onDrawerClose).not.toHaveBeenCalled()
  })

  it('closes the drawer when nothing is open over it (control)', async () => {
    const onDrawerClose = vi.fn()
    render(<Owner onDrawerClose={onDrawerClose}><DeleteLayer /></Owner>)
    await settle(50)
    await reRenderOwner()

    await nativeEscape()
    await settle()

    expect(onDrawerClose).toHaveBeenCalledOnce()
  })
})
