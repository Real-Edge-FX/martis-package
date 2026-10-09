import { useEffect, useRef, type RefObject } from 'react'
import { hasOpenLayer } from '@/lib/escapeLayers'
import { getModalLockCount } from '@/lib/historyLock'

const FOCUSABLE_SELECTOR = [
  'a[href]',
  'button:not([disabled])',
  'input:not([disabled]):not([type="hidden"])',
  'select:not([disabled])',
  'textarea:not([disabled])',
  'iframe',
  '[contenteditable="true"]',
  '[tabindex]',
].join(', ')

/** The elements of `container` that Tab reaches, in document order. */
export function focusableElements(container: HTMLElement): HTMLElement[] {
  return Array.from(container.querySelectorAll<HTMLElement>(FOCUSABLE_SELECTOR)).filter(
    (element) => element.tabIndex >= 0 && element.closest('[inert], [hidden]') === null,
  )
}

export interface ModalFocusOptions {
  /** Called on an Escape that no popup or modal above the surface took. */
  onClose: () => void
  /**
   * The element focus goes back to when the surface closes. Defaults to the
   * element that had focus when it opened.
   */
  returnFocus?: () => HTMLElement | null
}

/**
 * Focus as a modal surface (a dialog, a drawer) needs it, after the WAI-ARIA
 * dialog pattern (WCAG 2.2, 2.4.3 Focus Order and 2.1.2 No Keyboard Trap):
 * while `open` is true, focus moves to the first focusable element of `ref`,
 * Tab and Shift+Tab cycle inside it, and Escape calls `onClose`; when it
 * closes, focus goes back to `returnFocus()` (the element that had focus
 * when it opened, by default) if focus was inside the surface or nowhere.
 *
 * Escape closes the top layer only: a modal opened over the surface (its
 * history lock), a Martis popup or an open PrimeReact overlay takes it
 * first, as in the DrawerShell (see `lib/escapeLayers.ts`), and a key
 * pressed while focus is in another surface (the command palette, a dialog
 * opened over this one) is left to that surface. The surface renders its
 * own `role="dialog"` and `aria-modal="true"`.
 */
export function useModalFocus(
  ref: RefObject<HTMLElement | null>,
  open: boolean,
  options: ModalFocusOptions,
): void {
  const optionsRef = useRef(options)
  optionsRef.current = options

  useEffect(() => {
    const container = ref.current
    if (!open || container === null) return

    const previous = document.activeElement instanceof HTMLElement ? document.activeElement : null
    const initial = focusableElements(container)[0] ?? container
    initial.focus()
    // A surface still hidden by its stylesheet refuses focus: try once more
    // when the next frame has its styles.
    const retry = document.activeElement === initial ? 0 : requestAnimationFrame(() => {
      if (!container.contains(document.activeElement)) initial.focus()
    })

    // Capture phase, like the DrawerShell: the decision on Escape is taken
    // before a layer's own (bubble) listener closes it.
    function onKeyDown(event: KeyboardEvent) {
      if (container === null) return
      const active = document.activeElement
      const inside = active !== null && container.contains(active)
      if (!inside && active !== null && active !== document.body) return

      if (event.key === 'Escape') {
        if (event.defaultPrevented || getModalLockCount() > 0 || hasOpenLayer()) return
        event.preventDefault()
        optionsRef.current.onClose()
        return
      }

      if (event.key !== 'Tab') return
      const focusable = focusableElements(container)
      if (focusable.length === 0) {
        event.preventDefault()
        return
      }
      const first = focusable[0]
      const last = focusable[focusable.length - 1]
      if (event.shiftKey && (active === first || !inside)) {
        event.preventDefault()
        last.focus()
      } else if (!event.shiftKey && (active === last || !inside)) {
        event.preventDefault()
        first.focus()
      }
    }
    document.addEventListener('keydown', onKeyDown, true)

    return () => {
      cancelAnimationFrame(retry)
      document.removeEventListener('keydown', onKeyDown, true)

      // Leave focus alone when something outside the surface took it.
      const active = document.activeElement
      if (active !== null && active !== document.body && !container.contains(active)) return

      const target = optionsRef.current.returnFocus?.() ?? previous
      if (target !== null && target.isConnected && !container.contains(target)) target.focus()
    }
  }, [open, ref])
}
