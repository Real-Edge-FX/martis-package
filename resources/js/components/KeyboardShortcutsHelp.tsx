import { useEffect, useState, type CSSProperties } from "react"
import { useTranslation } from "react-i18next"
import { XIcon } from "@phosphor-icons/react"
import {
  addShortcut,
  listShortcuts,
  isHelpOverlayEnabled,
  type Shortcut,
} from "@/lib/keyboardShortcuts"
import { isMacPlatform } from "@/lib/platform"

/**
 * Help overlay listing every keyboard shortcut registered through
 * `addShortcut()`. Triggered by `Shift+?` from anywhere in the shell.
 *
 * Mounted once at the layout root. Reads the registry on open so it
 * always shows the live list (Tools and consumer plugins that register
 * shortcuts mid-session show up automatically).
 *
 * Visual surface follows the design system "Command Palette / Shortcuts"
 * pattern: `var(--martis-surface)` card on a `var(--martis-overlay)`
 * backdrop, hairline `var(--martis-border)` rule between rows, mono
 * `<kbd>` chips on `var(--martis-hover)` background.
 *
 * The whole component is a no-op when
 * `martis.keyboard_shortcuts.helpOverlay = false` is set in
 * `config/martis.php` — useful when the host app surfaces its own
 * help UI.
 */
export function KeyboardShortcutsHelp() {
  const { t } = useTranslation('messages')
  const [open, setOpen] = useState(false)
  const [snapshot, setSnapshot] = useState<readonly Shortcut[]>([])

  useEffect(() => {
    if (!isHelpOverlayEnabled()) return

    const dispose = addShortcut("shift+?", () => {
      setSnapshot(listShortcuts())
      setOpen(true)
    }, {
      description: "Show this help",
      group: "Help",
    })
    return dispose
  }, [])

  // Close on Escape — mirrors the design-system Command Palette.
  useEffect(() => {
    if (!open) return
    const onKey = (e: KeyboardEvent) => {
      if (e.key === "Escape") {
        e.preventDefault()
        setOpen(false)
      }
    }
    document.addEventListener("keydown", onKey, true)
    return () => document.removeEventListener("keydown", onKey, true)
  }, [open])

  if (!open) return null

  // Group shortcuts by their declared group (Navigation / Custom / Help / ...).
  const grouped = snapshot.reduce<Record<string, Shortcut[]>>((acc, s) => {
    const key = s.group ?? "Custom"
    acc[key] = acc[key] ?? []
    acc[key]!.push(s)
    return acc
  }, {})

  return (
    <div
      role="dialog"
      aria-modal="true"
      aria-labelledby="martis-shortcuts-title"
      onClick={() => setOpen(false)}
      style={{
        position: 'fixed',
        inset: 0,
        zIndex: 1100,
        background: 'var(--martis-overlay)',
        display: 'grid',
        placeItems: 'center',
        padding: 16,
      }}
    >
      <div
        onClick={(e) => e.stopPropagation()}
        style={{
          width: 480,
          maxWidth: '100%',
          maxHeight: '80vh',
          display: 'flex',
          flexDirection: 'column',
          background: 'var(--martis-surface)',
          border: '1px solid var(--martis-border)',
          borderRadius: 'var(--martis-radius-lg)',
          boxShadow: 'var(--martis-shadow-lg)',
          overflow: 'hidden',
        }}
      >
        {/* Header — same metrics as the Command Palette search row. */}
        <div
          style={{
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'space-between',
            gap: 10,
            padding: '14px 16px',
            borderBottom: '1px solid var(--martis-border)',
          }}
        >
          <h2
            id="martis-shortcuts-title"
            style={{
              fontFamily: 'var(--martis-font-heading)',
              fontSize: 'var(--martis-text-base)',
              fontWeight: 'var(--martis-weight-semibold)',
              color: 'var(--martis-text)',
              margin: 0,
            }}
          >
            {t('keyboard_shortcuts', 'Keyboard shortcuts')}
          </h2>
          <button
            type="button"
            aria-label={t('close', 'Close')}
            onClick={() => setOpen(false)}
            style={{
              display: 'inline-flex',
              alignItems: 'center',
              justifyContent: 'center',
              width: 28,
              height: 28,
              borderRadius: 'var(--martis-radius-sm)',
              background: 'transparent',
              border: '1px solid transparent',
              color: 'var(--martis-text-muted)',
              cursor: 'pointer',
            }}
            onMouseEnter={(e) => { e.currentTarget.style.background = 'var(--martis-hover)' }}
            onMouseLeave={(e) => { e.currentTarget.style.background = 'transparent' }}
          >
            <XIcon size={14} />
          </button>
        </div>

        {/* Body — grouped flat list, hairline between rows. */}
        <div style={{ overflowY: 'auto', padding: '6px 0 8px' }}>
          {Object.keys(grouped).length === 0 ? (
            <div
              style={{
                padding: '24px 16px',
                textAlign: 'center',
                fontSize: 'var(--martis-text-sm)',
                color: 'var(--martis-text-muted)',
              }}
            >
              {t('no_shortcuts_registered', 'No shortcuts are currently registered.')}
            </div>
          ) : (
            Object.entries(grouped).map(([group, items]) => (
              <section key={group}>
                <div
                  style={{
                    padding: '12px 16px 4px',
                    fontSize: 'var(--martis-text-xs)',
                    color: 'var(--martis-text-muted)',
                    textTransform: 'uppercase',
                    letterSpacing: '0.06em',
                    fontWeight: 'var(--martis-weight-semibold)',
                  }}
                >
                  {group}
                </div>
                {items.map((s, idx) => (
                  <div
                    key={`${s.combo}-${idx}`}
                    style={{
                      display: 'flex',
                      alignItems: 'center',
                      justifyContent: 'space-between',
                      gap: 12,
                      padding: '10px 16px',
                      fontSize: 'var(--martis-text-sm)',
                      color: 'var(--martis-text)',
                    }}
                  >
                    <span>{s.description ?? s.combo}</span>
                    <ShortcutChips combo={s.combo} />
                  </div>
                ))}
              </section>
            ))
          )}
        </div>

        {/* Footer — mirrors the Command Palette hint row. */}
        <div
          style={{
            display: 'flex',
            gap: 14,
            padding: '8px 14px',
            borderTop: '1px solid var(--martis-border)',
            fontSize: 'var(--martis-text-xs)',
            color: 'var(--martis-text-muted)',
            background: 'var(--martis-surface-alt)',
          }}
        >
          <span>
            <KeyChip text="Esc" />
            <span style={{ marginLeft: 8 }}>{t('close', 'Close')}</span>
          </span>
        </div>
      </div>
    </div>
  )
}

const kbdStyle: CSSProperties = {
  fontFamily: 'var(--martis-font-mono)',
  fontSize: 11,
  padding: '2px 6px',
  background: 'var(--martis-hover)',
  border: '1px solid var(--martis-border)',
  borderRadius: 'var(--martis-radius-sm)',
  color: 'var(--martis-text-muted)',
}

/** Render a combo string ("mod+k", "g r", "shift+?") as <kbd> chips. */
function ShortcutChips({ combo }: { combo: string }) {
  // Sequence (`g r`) — render both as separate kbd groups joined by " then ".
  if (combo.trim().includes(" ")) {
    const tokens = combo.trim().split(/\s+/)
    return (
      <span style={{ display: 'inline-flex', alignItems: 'center', gap: 6 }}>
        {tokens.map((token, idx) => (
          <span key={idx} style={{ display: 'inline-flex', alignItems: 'center', gap: 6 }}>
            {idx > 0 && (
              <span style={{ fontSize: 'var(--martis-text-xs)', color: 'var(--martis-text-muted)' }}>
                then
              </span>
            )}
            <KeyChip text={token.toUpperCase()} />
          </span>
        ))}
      </span>
    )
  }

  // Modifier combos like "mod+shift+k" — render parts joined visually.
  // The tokens stay inside a single <kbd> chip (matching the Command
  // Palette "⌘ K" shortcut pattern from the design system rather than
  // splitting modifiers into separate boxes).
  return <KeyChip text={prettifyCombo(combo)} />
}

function KeyChip({ text }: { text: string }) {
  return <kbd style={kbdStyle}>{text}</kbd>
}

function prettifyCombo(combo: string): string {
  return combo.split('+')
    .map((p) => prettifyKey(p.trim()))
    .filter(Boolean)
    .join(' ')
}

function prettifyKey(part: string): string {
  const lower = part.toLowerCase()
  if (lower === 'mod') return isMacPlatform() ? '⌘' : 'Ctrl'
  if (lower === 'cmd' || lower === 'meta' || lower === 'command') return '⌘'
  if (lower === 'ctrl' || lower === 'control') return 'Ctrl'
  if (lower === 'shift') return '⇧'
  if (lower === 'alt' || lower === 'option') return isMacPlatform() ? '⌥' : 'Alt'
  if (lower === 'escape') return 'Esc'
  if (lower === 'enter') return '↵'
  if (lower.length === 1) return part.toUpperCase()
  return part
}
