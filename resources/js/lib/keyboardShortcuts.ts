/**
 * Keyboard shortcuts registry.
 *
 * Single source of truth for every keyboard combo the Martis admin
 * shell, custom Tools and consumer plugins want to register. Built
 * directly on the browser `KeyboardEvent` API — no Mousetrap or other
 * 3rd-party dep, so the footprint stays minimal and the behaviour is
 * fully explicit.
 *
 * Public API (also exposed on `window.Martis.shortcuts`):
 *
 *   addShortcut(combo, handler, options?)
 *   disableShortcut(combo)
 *   listShortcuts(): readonly Shortcut[]
 *
 * Combo grammar:
 *   - Single key:        `'k'`, `'?'`, `'escape'`, `'/'`
 *   - With modifiers:    `'cmd+k'`, `'ctrl+shift+s'`, `'mod+/'`
 *     `mod` is the platform-natural primary modifier — `meta` on macOS,
 *     `ctrl` everywhere else. Use it instead of branching by OS.
 *   - Two-key sequence:  `'g r'`, `'g i'`
 *     The two tokens fire when pressed within `SEQUENCE_TIMEOUT` ms of
 *     each other, with the input not focused. Sequences cannot include
 *     modifiers — they are intended as Vim/Gmail-style mnemonics.
 *
 * By default a shortcut does NOT fire while the user is typing in an
 * `<input>`, `<textarea>`, `<select>`, or `contentEditable` element.
 * Pass `{ allowInInput: true }` to override (e.g. `cmd+k` palette).
 */

export interface ShortcutOptions {
  /** Short human-readable description; surfaces in the `?` help overlay. */
  description?: string
  /** Logical group used to organise the help overlay. Defaults to `'Custom'`. */
  group?: string
  /** Allow the shortcut to fire while an input/textarea/contentEditable is focused. */
  allowInInput?: boolean
  /** Stop propagation + preventDefault when the handler runs. Defaults to `true`. */
  preventDefault?: boolean
}

export interface Shortcut extends ShortcutOptions {
  combo: string
  handler: (event: KeyboardEvent) => void
}

interface ParsedCombo {
  /** Either a single keystroke or two ordered tokens (sequence). */
  tokens: ParsedKeystroke[]
}

interface ParsedKeystroke {
  key: string
  meta: boolean
  ctrl: boolean
  shift: boolean
  alt: boolean
}

const SEQUENCE_TIMEOUT = 1500

const isMac = typeof navigator !== 'undefined'
  && /Mac|iPhone|iPod|iPad/i.test(navigator.platform || navigator.userAgent || '')

function parseCombo(combo: string): ParsedCombo {
  // Sequence: two whitespace-separated tokens.
  const sequenceMatch = combo.trim().split(/\s+/)
  if (sequenceMatch.length === 2) {
    return {
      tokens: [parseKeystroke(sequenceMatch[0]!), parseKeystroke(sequenceMatch[1]!)],
    }
  }
  return { tokens: [parseKeystroke(combo)] }
}

function parseKeystroke(stroke: string): ParsedKeystroke {
  const parts = stroke.toLowerCase().split('+').map((p) => p.trim()).filter(Boolean)
  let meta = false
  let ctrl = false
  let shift = false
  let alt = false
  let key = ''

  for (const part of parts) {
    switch (part) {
      case 'cmd':
      case 'command':
      case 'meta':
        meta = true
        break
      case 'ctrl':
      case 'control':
        ctrl = true
        break
      case 'shift':
        shift = true
        break
      case 'alt':
      case 'option':
        alt = true
        break
      case 'mod':
        if (isMac) meta = true; else ctrl = true
        break
      default:
        key = part
    }
  }

  return { key, meta, ctrl, shift, alt }
}

function eventMatches(event: KeyboardEvent, stroke: ParsedKeystroke): boolean {
  return (
    event.key.toLowerCase() === stroke.key
    && event.metaKey === stroke.meta
    && event.ctrlKey === stroke.ctrl
    && event.shiftKey === stroke.shift
    && event.altKey === stroke.alt
  )
}

function isEditableTarget(event: KeyboardEvent): boolean {
  const target = event.target as HTMLElement | null
  if (!target) return false
  const tag = target.tagName
  if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') return true
  if (target.isContentEditable) return true
  return false
}

class KeyboardShortcutsRegistry {
  private shortcuts = new Map<string, Shortcut[]>()
  private parsedByCombo = new Map<string, ParsedCombo>()
  private bound = false
  private pendingSequence: { stroke: ParsedKeystroke; expiresAt: number } | null = null

  /** Register a new shortcut. Returns a disposer for symmetric cleanup. */
  add(combo: string, handler: Shortcut['handler'], options: ShortcutOptions = {}): () => void {
    const normalized = combo.trim()
    if (normalized === '') {
      throw new Error('keyboardShortcuts.add: combo string cannot be empty')
    }
    const entry: Shortcut = {
      combo: normalized,
      handler,
      group: 'Custom',
      preventDefault: true,
      ...options,
    }
    const list = this.shortcuts.get(normalized) ?? []
    list.push(entry)
    this.shortcuts.set(normalized, list)
    this.parsedByCombo.set(normalized, parseCombo(normalized))

    this.ensureBound()

    return () => {
      const remaining = (this.shortcuts.get(normalized) ?? []).filter((s) => s !== entry)
      if (remaining.length === 0) {
        this.shortcuts.delete(normalized)
        this.parsedByCombo.delete(normalized)
      } else {
        this.shortcuts.set(normalized, remaining)
      }
    }
  }

  /** Remove every handler registered under `combo`. */
  remove(combo: string): void {
    const normalized = combo.trim()
    this.shortcuts.delete(normalized)
    this.parsedByCombo.delete(normalized)
  }

  list(): readonly Shortcut[] {
    return Array.from(this.shortcuts.values()).flat()
  }

  /** Reset the registry. Mainly for tests. */
  reset(): void {
    this.shortcuts.clear()
    this.parsedByCombo.clear()
    this.pendingSequence = null
  }

  private ensureBound(): void {
    if (this.bound) return
    if (typeof document === 'undefined') return
    document.addEventListener('keydown', this.handler, true)
    this.bound = true
  }

  private handler = (event: KeyboardEvent): void => {
    const editable = isEditableTarget(event)
    const now = Date.now()

    // Sequence resolution — runs first so a pending `g` followed by `r`
    // beats any single-key `r` registration.
    if (this.pendingSequence !== null && now <= this.pendingSequence.expiresAt) {
      const seqMatch = this.findSequenceMatch(this.pendingSequence.stroke, event)
      this.pendingSequence = null
      if (seqMatch) {
        if (!editable || seqMatch.allowInInput) {
          if (seqMatch.preventDefault !== false) event.preventDefault()
          seqMatch.handler(event)
          return
        }
      }
    }

    // Single-key match.
    for (const [combo, parsed] of this.parsedByCombo) {
      if (parsed.tokens.length !== 1) continue
      if (!eventMatches(event, parsed.tokens[0]!)) continue
      const list = this.shortcuts.get(combo) ?? []
      for (const entry of list) {
        if (editable && !entry.allowInInput) continue
        if (entry.preventDefault !== false) event.preventDefault()
        entry.handler(event)
        return
      }
    }

    // Open a new sequence buffer if the current keystroke is the first
    // token of any registered 2-key sequence.
    for (const parsed of this.parsedByCombo.values()) {
      if (parsed.tokens.length !== 2) continue
      if (eventMatches(event, parsed.tokens[0]!)) {
        if (editable) return
        this.pendingSequence = { stroke: parsed.tokens[0]!, expiresAt: now + SEQUENCE_TIMEOUT }
        return
      }
    }
  }

  private findSequenceMatch(first: ParsedKeystroke, second: KeyboardEvent): Shortcut | null {
    for (const [combo, parsed] of this.parsedByCombo) {
      if (parsed.tokens.length !== 2) continue
      if (
        parsed.tokens[0]!.key === first.key
        && parsed.tokens[0]!.meta === first.meta
        && parsed.tokens[0]!.ctrl === first.ctrl
        && parsed.tokens[0]!.shift === first.shift
        && parsed.tokens[0]!.alt === first.alt
        && eventMatches(second, parsed.tokens[1]!)
      ) {
        const list = this.shortcuts.get(combo) ?? []
        return list[0] ?? null
      }
    }
    return null
  }
}

export const keyboardShortcuts = new KeyboardShortcutsRegistry()

/** Convenience top-level functions matching the public-API name expected by docs. */
export const addShortcut = (combo: string, handler: Shortcut['handler'], options?: ShortcutOptions): () => void =>
  keyboardShortcuts.add(combo, handler, options)

export const disableShortcut = (combo: string): void => keyboardShortcuts.remove(combo)

export const listShortcuts = (): readonly Shortcut[] => keyboardShortcuts.list()

// Expose on the global Martis object so consumers can invoke it from
// `boot.ts` (`window.Martis.shortcuts.add(...)`) without importing
// across module boundaries.
declare global {
  interface Window {
    Martis?: {
      shortcuts: {
        add: typeof addShortcut
        remove: typeof disableShortcut
        list: typeof listShortcuts
      }
    } & Record<string, unknown>
  }
}

if (typeof window !== 'undefined') {
  window.Martis = {
    ...(window.Martis ?? {}),
    shortcuts: {
      add: addShortcut,
      remove: disableShortcut,
      list: listShortcuts,
    },
  }
}
