import { useState } from "react"
import { X, Lightning } from "@phosphor-icons/react"
import type { CustomActionComponentProps } from "./ActionModal"

/**
 * Demo custom action component — takes full control of the action UI.
 * Demonstrates the Martis extension: custom action components with full lifecycle control.
 *
 * When an action has .component() set, this component replaces the entire modal.
 * It receives all action events and parameters via CustomActionComponentProps.
 */
export function DemoCustomAction({
  action,
  selectedIds,
  componentProps,
  onFieldsChange,
  onExecute,
  onClose,
  isExecuting,
}: CustomActionComponentProps) {
  const greeting = (componentProps.greeting as string) ?? "Select an option:"
  const options = (componentProps.options as string[]) ?? []
  const [selected, setSelected] = useState<string | null>(null)
  const [note, setNote] = useState("")

  function handleSelect(opt: string) {
    setSelected(opt)
    onFieldsChange({ selectedOption: opt, note })
  }

  function handleNoteChange(value: string) {
    setNote(value)
    onFieldsChange({ selectedOption: selected, note: value })
  }

  function handleSubmit() {
    onExecute({ selectedOption: selected, note })
  }

  return (
    <div className="flex items-center justify-center" style={{ position: 'absolute', inset: 0 }}>
      {/* Backdrop */}
      <div
        className="absolute inset-0"
        style={{ backgroundColor: 'rgba(0,0,0,0.4)' }}
        onClick={onClose}
      />

      {/* Custom panel — demonstrates full control */}
      <div
        className="relative w-full max-w-md rounded-xl shadow-xl mx-4"
        style={{
          backgroundColor: 'var(--martis-card)',
          border: '1px solid var(--martis-border)',
        }}
      >
        {/* Header */}
        <div
          className="flex items-center justify-between border-b px-6 py-4"
          style={{ borderColor: 'var(--martis-border)' }}
        >
          <span className="text-lg font-semibold" style={{ color: 'var(--martis-text)' }}>
            {action.name}
          </span>
          <button
            type="button"
            onClick={onClose}
            className="rounded-md p-1.5 transition-colors hover:bg-gray-100 dark:hover:bg-gray-800"
            style={{ color: 'var(--martis-text-muted)' }}
          >
            <X size={16} />
          </button>
        </div>

        {/* Body */}
        <div className="px-6 py-4 space-y-4">
          <p className="text-sm" style={{ color: 'var(--martis-text-muted)' }}>
            {greeting}
          </p>

          {selectedIds.length > 0 && (
            <p className="text-xs" style={{ color: 'var(--martis-text-muted)' }}>
              Applying to {selectedIds.length} record{selectedIds.length !== 1 ? 's' : ''}
            </p>
          )}

          <div className="grid grid-cols-3 gap-2">
            {options.map((opt) => (
              <button
                key={opt}
                type="button"
                onClick={() => handleSelect(opt)}
                className="rounded-lg border px-3 py-2 text-sm font-medium transition-all"
                style={{
                  backgroundColor: selected === opt ? 'var(--martis-accent)' : 'var(--martis-surface)',
                  borderColor: selected === opt ? 'var(--martis-accent)' : 'var(--martis-border)',
                  color: selected === opt ? '#ffffff' : 'var(--martis-text)',
                }}
              >
                {opt}
              </button>
            ))}
          </div>

          <div>
            <label className="mb-1 block text-sm font-medium" style={{ color: 'var(--martis-text)' }}>
              Additional Note
            </label>
            <textarea
              value={note}
              onChange={(e) => handleNoteChange(e.target.value)}
              rows={3}
              className="w-full rounded-lg border px-3 py-2 text-sm"
              style={{
                backgroundColor: 'var(--martis-input-bg)',
                borderColor: 'var(--martis-border)',
                color: 'var(--martis-text)',
              }}
              placeholder="Optional note..."
            />
          </div>

          {selected && (
            <div className="rounded-lg border px-3 py-2 text-xs" style={{ backgroundColor: 'var(--martis-surface)', borderColor: 'var(--martis-border)', color: 'var(--martis-text-muted)' }}>
              Selected: <strong style={{ color: 'var(--martis-accent)' }}>{selected}</strong>
            </div>
          )}
        </div>

        {/* Footer */}
        <div
          className="flex items-center justify-end gap-3 border-t px-6 py-4"
          style={{
            borderColor: 'var(--martis-border)',
            backgroundColor: 'var(--martis-surface)',
            borderRadius: '0 0 0.75rem 0.75rem',
          }}
        >
          <button
            type="button"
            onClick={onClose}
            disabled={isExecuting}
            className="inline-flex items-center gap-2 rounded-lg border px-4 py-2 text-sm font-medium transition-colors hover:opacity-90 disabled:opacity-50"
            style={{
              backgroundColor: 'var(--martis-input-bg)',
              borderColor: 'var(--martis-border)',
              color: 'var(--martis-text)',
            }}
          >
            <X size={14} />
            Cancel
          </button>
          <button
            type="button"
            onClick={handleSubmit}
            disabled={isExecuting || !selected}
            className="inline-flex items-center gap-2 rounded-lg px-4 py-2 text-sm font-medium text-white transition-colors hover:opacity-90 disabled:opacity-50"
            style={{ backgroundColor: 'var(--martis-accent)' }}
          >
            <Lightning size={14} />
            {isExecuting ? 'Processing...' : 'Confirm Choice'}
          </button>
        </div>
      </div>
    </div>
  )
}
