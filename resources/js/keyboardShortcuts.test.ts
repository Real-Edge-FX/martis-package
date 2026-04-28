import { describe, it, expect, beforeEach, vi, afterEach } from 'vitest'
import {
  keyboardShortcuts,
  addShortcut,
  disableShortcut,
  listShortcuts,
  isHelpOverlayEnabled,
} from '@/lib/keyboardShortcuts'

// -----------------------------------------------------------------------------
// keyboardShortcuts — public registry behaviour
// -----------------------------------------------------------------------------

function dispatch(key: string, opts: KeyboardEventInit = {}, target?: HTMLElement): KeyboardEvent {
  const event = new KeyboardEvent('keydown', {
    key,
    bubbles: true,
    cancelable: true,
    ...opts,
  })
  ;(target ?? document.body).dispatchEvent(event)
  return event
}

describe('keyboardShortcuts', () => {
  beforeEach(() => {
    keyboardShortcuts.reset()
  })

  afterEach(() => {
    keyboardShortcuts.reset()
  })

  it('fires the handler for a single-key shortcut', () => {
    const handler = vi.fn()
    addShortcut('escape', handler)

    dispatch('Escape')
    expect(handler).toHaveBeenCalledTimes(1)
  })

  it('fires for cmd+k (meta) on macOS-style modifier', () => {
    const handler = vi.fn()
    addShortcut('cmd+k', handler)

    dispatch('k', { metaKey: true })
    expect(handler).toHaveBeenCalledTimes(1)
  })

  it('does not fire when modifiers do not match exactly', () => {
    const handler = vi.fn()
    addShortcut('cmd+k', handler)

    // Pressed with shift held — should not match `cmd+k`.
    dispatch('k', { metaKey: true, shiftKey: true })
    expect(handler).not.toHaveBeenCalled()
  })

  it('does not fire while the user is typing in an input by default', () => {
    const handler = vi.fn()
    addShortcut('/', handler)

    const input = document.createElement('input')
    document.body.appendChild(input)
    dispatch('/', {}, input)
    document.body.removeChild(input)

    expect(handler).not.toHaveBeenCalled()
  })

  it('honours allowInInput: true to fire while typing', () => {
    const handler = vi.fn()
    addShortcut('cmd+k', handler, { allowInInput: true })

    const input = document.createElement('input')
    document.body.appendChild(input)
    dispatch('k', { metaKey: true }, input)
    document.body.removeChild(input)

    expect(handler).toHaveBeenCalledTimes(1)
  })

  it('disableShortcut removes a previously-registered handler', () => {
    const handler = vi.fn()
    addShortcut('escape', handler)
    disableShortcut('escape')

    dispatch('Escape')
    expect(handler).not.toHaveBeenCalled()
  })

  it('the disposer returned by addShortcut symmetric-cleans the registration', () => {
    const handler = vi.fn()
    const dispose = addShortcut('escape', handler)
    dispose()

    dispatch('Escape')
    expect(handler).not.toHaveBeenCalled()
  })

  it('listShortcuts returns every registered combo with its metadata', () => {
    addShortcut('cmd+k', () => {}, { description: 'Open palette', group: 'Navigation' })
    addShortcut('?', () => {}, { description: 'Help', group: 'Help' })

    const list = listShortcuts()
    expect(list).toHaveLength(2)
    expect(list.find((s) => s.combo === 'cmd+k')?.description).toBe('Open palette')
    expect(list.find((s) => s.combo === '?')?.group).toBe('Help')
  })

  it('fires a two-key sequence when the second key arrives within the timeout', () => {
    const handler = vi.fn()
    addShortcut('g r', handler)

    dispatch('g')
    dispatch('r')

    expect(handler).toHaveBeenCalledTimes(1)
  })

  it('does not fire a sequence when the second key does not match', () => {
    const handler = vi.fn()
    addShortcut('g r', handler)

    dispatch('g')
    dispatch('x')

    expect(handler).not.toHaveBeenCalled()
  })

  it('rejects empty combo strings at registration time', () => {
    expect(() => addShortcut('', () => {})).toThrow(/empty/)
  })

  // ---------------------------------------------------------------------------
  // Subsystem master switch — `martis.keyboard_shortcuts.enabled = false`
  // makes every addShortcut() call a no-op while still returning a disposer
  // so callers do not need to special-case the disabled path.
  // ---------------------------------------------------------------------------

  describe('master switch', () => {
    afterEach(() => {
      // Restore enabled state for downstream tests.
      ;(window as unknown as { MartisConfig?: Record<string, unknown> }).MartisConfig = {}
    })

    it('addShortcut is a no-op when keyboardShortcuts.enabled is false', () => {
      ;(window as unknown as { MartisConfig?: Record<string, unknown> }).MartisConfig = {
        keyboardShortcuts: { enabled: false },
      }
      const handler = vi.fn()
      const dispose = addShortcut('escape', handler)

      dispatch('Escape')
      expect(handler).not.toHaveBeenCalled()
      expect(listShortcuts()).toHaveLength(0)
      // Disposer is still callable (no-op) so consumers do not have to
      // branch on the enabled state.
      expect(() => dispose()).not.toThrow()
    })

    it('addShortcut works normally when enabled is true (default)', () => {
      ;(window as unknown as { MartisConfig?: Record<string, unknown> }).MartisConfig = {
        keyboardShortcuts: { enabled: true },
      }
      const handler = vi.fn()
      addShortcut('escape', handler)

      dispatch('Escape')
      expect(handler).toHaveBeenCalledTimes(1)
    })

    it('isHelpOverlayEnabled defaults to true when no config is present', () => {
      ;(window as unknown as { MartisConfig?: Record<string, unknown> }).MartisConfig = {}
      expect(isHelpOverlayEnabled()).toBe(true)
    })

    it('isHelpOverlayEnabled reads keyboardShortcuts.helpOverlay = false', () => {
      ;(window as unknown as { MartisConfig?: Record<string, unknown> }).MartisConfig = {
        keyboardShortcuts: { helpOverlay: false },
      }
      expect(isHelpOverlayEnabled()).toBe(false)
    })

    it('helpOverlay is independent of the master switch (enabled=true, helpOverlay=false)', () => {
      ;(window as unknown as { MartisConfig?: Record<string, unknown> }).MartisConfig = {
        keyboardShortcuts: { enabled: true, helpOverlay: false },
      }
      // Custom shortcut still registers; only the help overlay reader
      // returns false so the bundled Shift+? skips its own registration.
      const handler = vi.fn()
      addShortcut('escape', handler)
      dispatch('Escape')
      expect(handler).toHaveBeenCalledTimes(1)
      expect(isHelpOverlayEnabled()).toBe(false)
    })
  })
})
