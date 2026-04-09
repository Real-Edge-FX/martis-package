import { useState } from "react"

interface DemoCustomActionProps {
  greeting: string
  options: string[]
  onFieldsChange?: (fields: Record<string, unknown>) => void
}

/**
 * Demo custom action component — renders inside the action modal.
 * Shows a custom UI with selectable options instead of standard form fields.
 * This demonstrates the Martis extension: custom action components.
 */
export function DemoCustomAction({ greeting, options, onFieldsChange }: DemoCustomActionProps) {
  const [selected, setSelected] = useState<string | null>(null)
  const [note, setNote] = useState("")

  function handleSelect(opt: string) {
    setSelected(opt)
    onFieldsChange?.({ selectedOption: opt, note })
  }

  function handleNoteChange(value: string) {
    setNote(value)
    onFieldsChange?.({ selectedOption: selected, note: value })
  }

  return (
    <div className="space-y-4">
      <p className="text-sm font-medium" style={{ color: "var(--martis-text)" }}>
        {greeting}
      </p>
      <div className="grid grid-cols-3 gap-2">
        {options.map((opt) => (
          <button
            key={opt}
            type="button"
            onClick={() => handleSelect(opt)}
            className="rounded-lg border px-3 py-2 text-sm font-medium transition-all"
            style={{
              backgroundColor: selected === opt ? "var(--martis-accent)" : "var(--martis-surface)",
              borderColor: selected === opt ? "var(--martis-accent)" : "var(--martis-border)",
              color: selected === opt ? "#ffffff" : "var(--martis-text)",
            }}
          >
            {opt}
          </button>
        ))}
      </div>
      <div>
        <label className="mb-1 block text-sm font-medium" style={{ color: "var(--martis-text)" }}>
          Additional Note
        </label>
        <textarea
          value={note}
          onChange={(e) => handleNoteChange(e.target.value)}
          rows={3}
          className="w-full rounded-lg border px-3 py-2 text-sm"
          style={{
            backgroundColor: "var(--martis-input-bg)",
            borderColor: "var(--martis-border)",
            color: "var(--martis-text)",
          }}
          placeholder="Optional note..."
        />
      </div>
      {selected && (
        <div className="rounded-lg border px-3 py-2 text-xs" style={{ backgroundColor: "var(--martis-surface)", borderColor: "var(--martis-border)", color: "var(--martis-text-muted)" }}>
          Selected: <strong style={{ color: "var(--martis-accent)" }}>{selected}</strong>
        </div>
      )}
    </div>
  )
}
