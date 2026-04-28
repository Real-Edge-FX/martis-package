# Keyboard Shortcuts

Martis ships a tiny in-house registry for keyboard shortcuts. Every combo the admin shell, custom Tools, or consumer plugins want to bind goes through one API — so the help overlay (`Shift+?`) always lists the live set, and conflict detection has a single point of truth.

No 3rd-party dependency: implementation lives in `resources/js/lib/keyboardShortcuts.ts` (~200 lines on top of the browser `KeyboardEvent` API).

## Public API

```ts
import { addShortcut, disableShortcut, listShortcuts } from '@/lib/keyboardShortcuts'

// Or, from a consumer's boot.ts (no module imports needed):
window.Martis.shortcuts.add('mod+s', handler)
```

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

## Behaviour around form focus

By default a shortcut is suppressed when the user is typing in an `<input>`, `<textarea>`, `<select>`, or `contentEditable` element — typing "k" in a search box should not toggle a palette.

Override with `{ allowInInput: true }` for combos that should always fire (typically `mod+k`-style global commands).

## Recipes

### Custom Tool registers an open-shortcut

```ts
// In your Tool's React entry component.
import { useEffect } from 'react'
import { addShortcut } from '@/lib/keyboardShortcuts'
import { useNavigate } from 'react-router-dom'

export function MyDeploymentsTool() {
  const navigate = useNavigate()

  useEffect(() => {
    return addShortcut('g d', () => navigate('/tools/deployments'), {
      description: 'Go to Deployments tool',
      group: 'Tools',
    })
  }, [navigate])

  return <div>...</div>
}
```

### Replace the bundled palette shortcut

If you want to bind the palette to `mod+/` instead of `mod+k`, run this at app boot (e.g. in your `boot.ts`):

```ts
import { disableShortcut, addShortcut } from '@/lib/keyboardShortcuts'

disableShortcut('mod+k')
addShortcut('mod+/', () => {
  // your palette opener
}, { description: 'Open palette', group: 'Navigation', allowInInput: true })
```

### Form-save shortcut on a custom page

```ts
useEffect(() => {
  return addShortcut('mod+s', (e) => {
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

The registry is written to be testable without jsdom shenanigans — call `addShortcut`, dispatch a `KeyboardEvent` against `document.body`, assert.

```ts
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

See `resources/js/keyboardShortcuts.test.ts` for the full suite (11 specs covering single keys, modifiers, sequences, input-focus suppression, listing, dispose semantics).
