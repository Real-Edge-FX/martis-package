import { useEffect, useRef, type RefObject } from 'react'

/**
 * Escape closes the top layer only.
 *
 * A popup (a menu, a picker, a dropdown) opened inside a drawer is a layer
 * over it: Escape must close the popup and leave the drawer, and the form
 * the user is filling in it, where they are. A second Escape then closes
 * the drawer. The layers coordinate through this module:
 *
 *   • A Martis popup calls {@link useEscapeLayer} while it is open. It
 *     joins a stack, and on Escape only the popup on top of the stack
 *     closes.
 *
 *   • The DrawerShell asks {@link hasOpenLayer} when an Escape arrives
 *     (React closes a popup only after the event), and leaves it to the layer when
 *     one is open: a Martis popup on the stack, or an open PrimeReact
 *     overlay (Dropdown, MultiSelect, Calendar, AutoComplete, SplitButton,
 *     ColorPicker, OverlayPanel, popup Menu), which PrimeReact closes on
 *     Escape itself. Modals keep their own lock (`getModalLockCount()` in
 *     historyLock.ts).
 */

let nextLayerId = 0
const openLayers: number[] = []

/**
 * An open PrimeReact overlay: every connected overlay carries
 * `data-pr-is-overlay` (or, for the OverlayPanel and the popup menus, its
 * own class) only while it is mounted, and an overlay leaving (the exit
 * transition) no longer counts, so a second Escape reaches the drawer.
 */
const PRIME_OVERLAY_SELECTOR = [
  '[data-pr-is-overlay]',
  '.p-overlaypanel',
  '.p-menu-overlay',
  '.p-tieredmenu-overlay',
  '.p-contextmenu',
]
  .map((selector) => `${selector}:not(.p-connected-overlay-exit):not(.p-connected-overlay-exit-active):not(.p-overlaypanel-exit):not(.p-overlaypanel-exit-active)`)
  .join(', ')

export function getOpenLayerCount(): number {
  return openLayers.length
}

/** Whether a popup is open over the page: a Martis layer or a PrimeReact overlay. */
export function hasOpenLayer(): boolean {
  if (openLayers.length > 0) return true
  if (typeof document === 'undefined') return false

  return document.querySelector(PRIME_OVERLAY_SELECTOR) !== null
}

/**
 * Register a popup as a layer while `open` is true: Escape calls `close`
 * when this popup is the top layer, and a drawer underneath stays open.
 */
export function useEscapeLayer(open: boolean, close: () => void): void {
  const closeRef = useRef(close)
  closeRef.current = close

  useEffect(() => {
    if (!open) return

    nextLayerId += 1
    const id = nextLayerId
    openLayers.push(id)

    function onKeyDown(e: KeyboardEvent) {
      if (e.key !== 'Escape') return
      if (openLayers[openLayers.length - 1] !== id) return
      // Taken: a drawer underneath leaves a handled Escape alone.
      e.preventDefault()
      closeRef.current()
    }
    document.addEventListener('keydown', onKeyDown)

    return () => {
      document.removeEventListener('keydown', onKeyDown)
      const index = openLayers.indexOf(id)
      if (index !== -1) openLayers.splice(index, 1)
    }
  }, [open])
}

/** Whether `dialog` is the modal dialog on top: modals portal into the body in the order they open. */
function isTopDialog(dialog: HTMLElement | null): boolean {
  if (!dialog) return true
  const dialogs = document.querySelectorAll('[role="dialog"][aria-modal="true"]')
  return dialogs[dialogs.length - 1] === dialog
}

/**
 * Close a modal that holds a form on Escape, once nothing over it takes the key.
 *
 * It decides in the capture phase, before the form's own handlers, as the
 * DrawerShell does: a picker or a PrimeReact overlay open in the form still
 * counts as an open layer, and the Escape is left to it (the modal closes on
 * the next one). A later check would be wrong both ways: a PrimeReact
 * Dropdown marks every Escape it sees handled, open or not, and a layer that
 * closes first is gone by the time a bubbling listener asks. A modal opened
 * over this one (`dialogRef` not the last dialog) takes the key instead.
 */
export function useModalEscape(open: boolean, close: () => void, dialogRef: RefObject<HTMLElement | null>): void {
  const closeRef = useRef(close)
  closeRef.current = close

  useEffect(() => {
    if (!open) return

    function onKeyDown(e: KeyboardEvent) {
      if (e.key !== 'Escape') return
      if (e.defaultPrevented || hasOpenLayer() || !isTopDialog(dialogRef.current)) return
      // Taken: a drawer underneath leaves a handled Escape alone.
      e.preventDefault()
      closeRef.current()
    }
    document.addEventListener('keydown', onKeyDown, true)

    return () => document.removeEventListener('keydown', onKeyDown, true)
  }, [open, dialogRef])
}
