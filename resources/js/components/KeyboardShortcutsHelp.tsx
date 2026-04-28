import { useEffect, useState } from "react"
import { Dialog } from "primereact/dialog"
import { useTranslation } from "react-i18next"
import { addShortcut, listShortcuts, type Shortcut } from "@/lib/keyboardShortcuts"
import { isMacPlatform } from "@/lib/platform"

/**
 * Help overlay listing every keyboard shortcut registered through
 * `addShortcut()`. Triggered by `Shift+?` from anywhere in the shell.
 *
 * Mounted once at the layout root. Reads the registry on open so it
 * always shows the live list (Tools and consumer plugins that register
 * shortcuts mid-session show up automatically).
 */
export function KeyboardShortcutsHelp() {
  const { t } = useTranslation('messages')
  const [open, setOpen] = useState(false)
  const [snapshot, setSnapshot] = useState<readonly Shortcut[]>([])

  useEffect(() => {
    const dispose = addShortcut("shift+?", () => {
      setSnapshot(listShortcuts())
      setOpen(true)
    }, {
      description: "Show this help",
      group: "Help",
    })
    return dispose
  }, [])

  // Group shortcuts by their declared group (Navigation / Custom / Help / ...).
  const grouped = snapshot.reduce<Record<string, Shortcut[]>>((acc, s) => {
    const key = s.group ?? "Custom"
    acc[key] = acc[key] ?? []
    acc[key]!.push(s)
    return acc
  }, {})

  return (
    <Dialog
      header={t('keyboard_shortcuts', 'Keyboard shortcuts')}
      visible={open}
      onHide={() => setOpen(false)}
      style={{ width: '480px', maxWidth: '90vw' }}
      modal
      dismissableMask
    >
      {Object.keys(grouped).length === 0 ? (
        <div className="text-sm" style={{ color: 'var(--martis-text-muted)' }}>
          {t('no_shortcuts_registered', 'No shortcuts are currently registered.')}
        </div>
      ) : (
        <div className="flex flex-col gap-4">
          {Object.entries(grouped).map(([group, items]) => (
            <section key={group}>
              <h3
                className="text-xs uppercase tracking-wider mb-2"
                style={{ color: 'var(--martis-text-muted)' }}
              >
                {group}
              </h3>
              <ul className="flex flex-col gap-1.5">
                {items.map((s) => (
                  <li key={s.combo} className="flex items-center justify-between gap-3">
                    <span className="text-sm" style={{ color: 'var(--martis-text)' }}>
                      {s.description ?? s.combo}
                    </span>
                    <ShortcutKey combo={s.combo} />
                  </li>
                ))}
              </ul>
            </section>
          ))}
        </div>
      )}
    </Dialog>
  )
}

/** Renders a combo string ("mod+k", "g r") as styled <kbd> blocks. */
function ShortcutKey({ combo }: { combo: string }) {
  // Sequence (`g r`) — render both as separate kbd groups joined by " then ".
  if (combo.trim().includes(" ")) {
    const tokens = combo.trim().split(/\s+/)
    return (
      <span className="flex items-center gap-1">
        {tokens.map((token, idx) => (
          <span key={idx} className="flex items-center gap-1">
            {idx > 0 && (
              <span className="text-xs" style={{ color: 'var(--martis-text-muted)' }}>
                then
              </span>
            )}
            <KeyChip text={token} />
          </span>
        ))}
      </span>
    )
  }

  const parts = combo.split("+").map((p) => p.trim())
  return (
    <span className="flex items-center gap-1">
      {parts.map((part, idx) => (
        <KeyChip key={idx} text={prettifyKey(part)} />
      ))}
    </span>
  )
}

function KeyChip({ text }: { text: string }) {
  return (
    <kbd
      className="px-1.5 py-0.5 text-xs rounded font-mono"
      style={{
        background: 'var(--martis-surface-hover)',
        color: 'var(--martis-text)',
        border: '1px solid var(--martis-border)',
      }}
    >
      {text}
    </kbd>
  )
}

function prettifyKey(part: string): string {
  const lower = part.toLowerCase()
  if (lower === 'mod') return isMacPlatform() ? '⌘' : 'Ctrl'
  if (lower === 'cmd' || lower === 'meta' || lower === 'command') return '⌘'
  if (lower === 'ctrl' || lower === 'control') return 'Ctrl'
  if (lower === 'shift') return 'Shift'
  if (lower === 'alt' || lower === 'option') return isMacPlatform() ? '⌥' : 'Alt'
  if (lower === 'escape') return 'Esc'
  if (lower === 'enter') return '↵'
  if (lower.length === 1) return part.toUpperCase()
  return part
}
