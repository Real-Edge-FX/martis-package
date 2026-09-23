# Keyboard Shortcuts

Martis ships a tiny in-house registry for keyboard shortcuts. Every combo the admin shell, custom Tools, or consumer plugins want to bind goes through one API — so the help overlay (`Shift+?`) always lists the live set, and conflict detection has a single point of truth.

No 3rd-party dependency: implementation lives in `resources/js/lib/keyboardShortcuts.ts` (~200 lines on top of the browser `KeyboardEvent` API).

## Public API

An extension reaches the registry through `window.Martis.shortcuts`, which the SPA sets before it loads your extension bundle:

```ts
window.Martis.shortcuts.add('mod+s', handler)   // addShortcut: returns a disposer
window.Martis.shortcuts.remove('mod+s')          // disableShortcut
window.Martis.shortcuts.list()                   // listShortcuts
```

Inside the package the same three functions are module exports, which `window.Martis.shortcuts` maps 1:1 (`add`, `remove`, `list`):

```ts
// Package-internal: resources/js/lib/keyboardShortcuts.ts, not on @martis/runtime.
import { addShortcut, disableShortcut, listShortcuts } from '@/lib/keyboardShortcuts'
```

The sections below use the function names; from an extension, call `window.Martis.shortcuts.add`, `.remove` and `.list` with the same arguments.

### `addShortcut(combo, handler, options?)`

Registers a new shortcut. Returns a disposer for symmetric cleanup (call it from `useEffect` cleanup, Tool teardown, etc.).

```ts
const dispose = addShortcut('mod+k', () => openPalette(), {
  description: 'Open command palette',
  group: 'Navigation',
  allowInInput: true,
})

// later
dispose()
```

| Option | Type | Default | Description |
|---|---|---|---|
| `description` | `string` | combo | Human-readable label rendered in the help overlay. |
| `group` | `string` | `'Custom'` | Group heading the help overlay clusters under. |
| `allowInInput` | `boolean` | `false` | Fire even when an `<input>`/`<textarea>`/`<select>`/`contentEditable` element is focused. Use sparingly — typically only for global commands like `mod+k`. |
| `preventDefault` | `boolean` | `true` | Call `event.preventDefault()` before invoking the handler. |

### `disableShortcut(combo)`

Removes every handler registered under `combo`.

```ts
disableShortcut('mod+k') // suppresses the bundled palette toggle
```

### `listShortcuts(): readonly Shortcut[]`

Returns the live registration set (combo, handler, group, description). Used internally by the help overlay; consumer code can read it for diagnostics.

## Combo grammar

```
'k'                  # single key
'cmd+k'              # explicit macOS modifier
'ctrl+k'             # explicit Windows/Linux modifier
'mod+k'              # platform-natural primary modifier (cmd on macOS, ctrl elsewhere)
'shift+?'            # combined modifiers
'g r'                # two-key sequence (Vim/Gmail-style)
'/'                  # punctuation
'escape'             # named keys
```

Modifier keywords (case-insensitive): `cmd`, `command`, `meta`, `ctrl`, `control`, `shift`, `alt`, `option`. The literal `mod` is rewritten at parse time:

| Platform | `mod` |
|---|---|
| macOS | `meta` (the ⌘ key) |
| Windows / Linux | `ctrl` |

Use `mod` whenever you mean "the platform-natural primary modifier" — saves you a `navigator.platform` check.

### Sequences

A sequence is two whitespace-separated tokens (e.g. `'g r'`). The handler fires when:

1. The first token matches a key event (must NOT happen while a form element is focused);
2. The second token arrives within 1500 ms;
3. Modifiers in either token match the event exactly.

Sequences cannot have modifiers — they are intended as quick mnemonic chords. For modifier combos use the `+` form.

## Built-in shortcuts

These ship with every Martis install:

| Combo | Action |
|---|---|
| `mod+k` | Open the command palette (toggle on Topbar; open on TopnavLayout) |
| `/` | Open the command palette when no input is focused |
| `shift+?` | Open the keyboard-shortcuts help overlay |

## Help overlay

`<KeyboardShortcutsHelp>` is mounted once at the layout root (`resources/js/components/Layout.tsx`). It listens for `Shift+?` from anywhere in the shell and surfaces every registered combo grouped by `group`. The overlay reads the registry on open, so shortcuts registered mid-session (by a Tool that mounted late, for instance) appear without a refresh.

The visual surface follows the design-system "Command Palette / Shortcuts" pattern: `var(--martis-surface)` card on a `var(--martis-overlay)` backdrop, a hairline `var(--martis-border)` rule between rows, and `<kbd>` chips in `var(--martis-font-mono)` on `var(--martis-hover)`. `Esc` and a click on the backdrop both close it.

## Disabling the subsystem

Two independent toggles in `config/martis.php`, both default to `true`:

```php
'keyboard_shortcuts' => [
    'enabled' => true,       // master switch — addShortcut() becomes a no-op when false
    'helpOverlay' => true,   // skip the bundled Shift+? overlay registration only
],
```

| Toggle | Effect when `false` |
|---|---|
| `enabled` | Every `addShortcut()` call returns a no-op disposer — no event listener is bound, none of the bundled combos (`mod+k`, `/`, `shift+?`) fire, and `listShortcuts()` returns an empty array. Use it when the host app ships a custom keyboard layer or wants to forbid global hotkeys. |
| `helpOverlay` | `addShortcut()` still works for every other combo; only the bundled `Shift+?` registration is skipped. Use it when the host app wants to surface its own help UI instead of the dialog above. |

The flags are read live (every registration consults the current value), so toggling them at runtime works for late-mounting Tools. The defaults match historic behaviour, so existing installs see no change.

Env vars: `MARTIS_KEYBOARD_SHORTCUTS_ENABLED` and `MARTIS_KEYBOARD_SHORTCUTS_HELP_OVERLAY` — both bool.

## Behaviour around form focus

By default a shortcut is suppressed when the user is typing in an `<input>`, `<textarea>`, `<select>`, or `contentEditable` element — typing "k" in a search box should not toggle a palette.

Override with `{ allowInInput: true }` for combos that should always fire (typically `mod+k`-style global commands).

## Recipes

### Custom Tool registers an open-shortcut

```ts
// In your Tool's React entry component.
import { useEffect } from 'react'
import { useNavigate } from 'react-router-dom'

export function MyDeploymentsTool() {
  const navigate = useNavigate()

  useEffect(() => {
    return window.Martis.shortcuts.add('g d', () => navigate('/tools/deployments'), {
      description: 'Go to Deployments tool',
      group: 'Tools',
    })
  }, [navigate])

  return <div>...</div>
}
```

### Take over a bundled shortcut

The topbar registers `mod+k` and `/` when the shell mounts, and the SPA mounts only after your extension bundle has run, so `window.Martis.shortcuts.remove('mod+k')` in the extension entry finds nothing to remove yet. Take the combo over instead: when several handlers share a combo, the one registered first runs and the others do not, so a handler your extension entry registers wins over the bundled one:

```ts
// resources/js/martis-extensions/index.ts (runs before the shell mounts)
window.Martis.shortcuts.add('mod+k', () => {
  // your command
}, { description: 'Open my launcher', group: 'Navigation', allowInInput: true })
```

Pass `allowInInput: true` when the bundled handler has it (`mod+k` does): a handler without it is skipped while an input has focus, and the bundled one runs instead.

### Form-save shortcut on a custom page

```ts
useEffect(() => {
  return window.Martis.shortcuts.add('mod+s', (e) => {
    e.preventDefault()
    formRef.current?.submit()
  }, {
    description: 'Save the current form',
    group: 'Editing',
    allowInInput: true,
  })
}, [])
```

## Testing

The registry is written to be testable without jsdom shenanigans — call `addShortcut`, dispatch a `KeyboardEvent` against `document.body`, assert. This is the package's own suite:

```ts
// Package-internal: the package's Vitest suite imports the module directly.
import { keyboardShortcuts, addShortcut } from '@/lib/keyboardShortcuts'

beforeEach(() => keyboardShortcuts.reset()) // clears everything between tests

it('fires on cmd+k', () => {
  const handler = vi.fn()
  addShortcut('cmd+k', handler)
  document.body.dispatchEvent(
    new KeyboardEvent('keydown', { key: 'k', metaKey: true, bubbles: true }),
  )
  expect(handler).toHaveBeenCalled()
})
```

See `resources/js/keyboardShortcuts.test.ts` for the full suite (16 specs covering single keys, modifiers, sequences, input-focus suppression, listing, dispose semantics, and the master-switch behaviour).
