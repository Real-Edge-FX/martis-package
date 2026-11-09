import { describe, it, expect, afterEach, vi } from 'vitest'
import { render, cleanup } from '@testing-library/react'
import { DrawerShell } from './DrawerShell'
import { getScrollLockCount, lockDocumentScroll } from '@/lib/scrollLock'

/*
 * Drawers on a phone: the panel is the whole viewport (a configured 720px
 * width used to push its left part off a 390px screen), and the page
 * behind does not scroll while any drawer is open (one lock for a stack).
 */

function setViewport(width: number): void {
  Object.defineProperty(window, 'innerWidth', { configurable: true, writable: true, value: width })
}

function panel(container: HTMLElement = document.body): HTMLElement {
  const el = container.querySelector<HTMLElement>('.martis-drawer-shell')
  if (!el) throw new Error('drawer panel not rendered')
  return el
}

afterEach(() => {
  cleanup()
  setViewport(1024)
  document.body.style.cssText = ''
  document.documentElement.style.cssText = ''
})

describe('DrawerShell width', () => {
  it('takes the full viewport at phone width, whatever the configured width', () => {
    setViewport(390)
    render(<DrawerShell title="Conversation" width="780px" onClose={() => {}}>body</DrawerShell>)
    const el = panel()
    expect(el.style.width).toBe('100vw')
    expect(el.style.minWidth).toBe('100vw')
    expect(el.style.flex).toBe('0 0 100vw')
    expect(el.style.width).not.toContain('780px')
  })

  it('hides expand and fullscreen at phone width and keeps close', () => {
    setViewport(375)
    const { getByText } = render(<DrawerShell title="Ticket" onClose={() => {}}>body</DrawerShell>)
    const actions = document.querySelectorAll('.martis-drawer-actions button')
    expect(actions).toHaveLength(1)
    expect(actions[0].getAttribute('data-pr-tooltip')).toBe('Close')
    expect(getByText('Ticket')).toBeTruthy()
  })

  it('keeps the configured width on desktop, capped at the viewport', () => {
    setViewport(1280)
    render(<DrawerShell title="Ticket" width="720px" onClose={() => {}}>body</DrawerShell>)
    const el = panel()
    expect(el.style.width).toBe('min(720px, 100vw)')
    expect(el.style.minWidth).toBe('min(720px, 100vw)')
    expect(document.querySelectorAll('.martis-drawer-actions button')).toHaveLength(3)
  })

  it('marks the body as the contained scroller and the backdrop as non-scrolling', () => {
    setViewport(390)
    render(<DrawerShell title="Ticket" onClose={() => {}}>body</DrawerShell>)
    expect(document.querySelector('.martis-drawer-body')?.className).toContain('overflow-y-auto')
    expect(document.querySelector('.martis-drawer-backdrop')).not.toBeNull()
  })
})

describe('Drawer scroll lock', () => {
  it('pins the page on open and restores it on close', () => {
    const scrollTo = vi.spyOn(window, 'scrollTo').mockImplementation(() => {})
    Object.defineProperty(window, 'scrollY', { configurable: true, value: 420 })
    const { unmount } = render(<DrawerShell title="Ticket" onClose={() => {}}>body</DrawerShell>)
    expect(getScrollLockCount()).toBe(1)
    expect(document.body.style.position).toBe('fixed')
    expect(document.body.style.top).toBe('-420px')
    expect(document.documentElement.style.overflow).toBe('hidden')

    unmount()
    expect(getScrollLockCount()).toBe(0)
    expect(document.body.style.position).toBe('')
    expect(document.body.style.top).toBe('')
    expect(document.documentElement.style.overflow).toBe('')
    expect(scrollTo).toHaveBeenCalledWith(0, 420)
    Object.defineProperty(window, 'scrollY', { configurable: true, value: 0 })
    scrollTo.mockRestore()
  })

  it('holds one lock for nested drawers and releases it with the last one', () => {
    const scrollTo = vi.spyOn(window, 'scrollTo').mockImplementation(() => {})
    const outer = render(<DrawerShell title="Ticket" onClose={() => {}}>outer</DrawerShell>)
    const inner = render(<DrawerShell title="Reply" onClose={() => {}}>inner</DrawerShell>)
    expect(getScrollLockCount()).toBe(2)
    expect(document.body.style.position).toBe('fixed')

    inner.unmount()
    expect(getScrollLockCount()).toBe(1)
    expect(document.body.style.position).toBe('fixed')
    expect(scrollTo).not.toHaveBeenCalled()

    outer.unmount()
    expect(getScrollLockCount()).toBe(0)
    expect(document.body.style.position).toBe('')
    expect(scrollTo).toHaveBeenCalledTimes(1)
    scrollTo.mockRestore()
  })

  it('restores the inline styles the page had before the lock', () => {
    document.body.style.overflow = 'auto'
    const release = lockDocumentScroll()
    expect(document.body.style.overflow).toBe('hidden')
    release()
    release()
    expect(getScrollLockCount()).toBe(0)
    expect(document.body.style.overflow).toBe('auto')
  })
})
