import { useEffect } from 'react'

/**
 * Reference-counted lock on the document scroll, shared by every surface
 * that covers the page (drawers, the mobile sidebar). The first lock pins
 * the page and the last release restores it, so a stack of drawers (a
 * relation drawer opened from a drawer) holds a single lock.
 *
 * `overflow: hidden` on html/body does not stop iOS Safari from scrolling
 * the page under a fixed overlay, so the body is also pinned with
 * `position: fixed` at the saved scroll offset, and the offset is restored
 * on release.
 */

let lockCount = 0
let savedScrollY = 0
let savedStyles: {
  htmlOverflow: string
  bodyOverflow: string
  bodyPosition: string
  bodyTop: string
  bodyLeft: string
  bodyRight: string
  bodyWidth: string
} | null = null

function applyLock(): void {
  const html = document.documentElement
  const body = document.body
  savedScrollY = window.scrollY || window.pageYOffset || 0
  savedStyles = {
    htmlOverflow: html.style.overflow,
    bodyOverflow: body.style.overflow,
    bodyPosition: body.style.position,
    bodyTop: body.style.top,
    bodyLeft: body.style.left,
    bodyRight: body.style.right,
    bodyWidth: body.style.width,
  }
  html.style.overflow = 'hidden'
  body.style.overflow = 'hidden'
  body.style.position = 'fixed'
  body.style.top = `-${savedScrollY}px`
  body.style.left = '0'
  body.style.right = '0'
  body.style.width = '100%'
  html.setAttribute('data-martis-scroll-locked', 'true')
}

function releaseLock(): void {
  const html = document.documentElement
  const body = document.body
  const styles = savedStyles
  savedStyles = null
  if (styles) {
    html.style.overflow = styles.htmlOverflow
    body.style.overflow = styles.bodyOverflow
    body.style.position = styles.bodyPosition
    body.style.top = styles.bodyTop
    body.style.left = styles.bodyLeft
    body.style.right = styles.bodyRight
    body.style.width = styles.bodyWidth
  }
  html.removeAttribute('data-martis-scroll-locked')
  try {
    window.scrollTo(0, savedScrollY)
  } catch {
    /* jsdom and old engines: the offset stays where the browser put it */
  }
}

/**
 * Locks the document scroll and returns the release function. Calling the
 * release more than once is a no-op.
 */
export function lockDocumentScroll(): () => void {
  if (typeof document === 'undefined') return () => {}
  if (lockCount === 0) applyLock()
  lockCount += 1
  let released = false
  return () => {
    if (released) return
    released = true
    lockCount -= 1
    if (lockCount === 0) releaseLock()
  }
}

/** Number of active locks (tests and diagnostics). */
export function getScrollLockCount(): number {
  return lockCount
}

/** Holds a document scroll lock while `active` is true. */
export function useScrollLock(active = true): void {
  useEffect(() => {
    if (!active) return
    return lockDocumentScroll()
  }, [active])
}
