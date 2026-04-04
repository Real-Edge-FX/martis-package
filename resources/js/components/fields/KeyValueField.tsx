import { useState } from 'react'
import { Plus, Trash } from '@phosphor-icons/react'
import type { FieldDisplayProps, FieldInputProps } from './types'

interface KeyValueRow {
  key: string
  value: string
}

function isKeyValueRows(v: unknown): v is KeyValueRow[] {
  return Array.isArray(v) && (v.length === 0 || (typeof v[0] === 'object' && v[0] !== null && 'key' in v[0]))
}

function toRows(v: unknown): KeyValueRow[] {
  if (!v) return []
  if (isKeyValueRows(v)) return v
  if (typeof v === 'object' && !Array.isArray(v)) {
    return Object.entries(v as Record<string, unknown>).map(([key, value]) => ({
      key,
      value: String(value ?? ''),
    }))
  }
  return []
}

// ---------------------------------------------------------------------------
// Display
// ---------------------------------------------------------------------------

export function KeyValueFieldDisplay({ field, value }: FieldDisplayProps) {
  const rows = toRows(value)

  if (rows.length === 0) {
    return <span className="martis-text-muted">—</span>
  }

  const keyLabel = (field as Record<string, unknown>).keyLabel as string | undefined
  const valueLabel = (field as Record<string, unknown>).valueLabel as string | undefined

  return (
    <div className="overflow-x-auto">
      <table className="w-full text-sm">
        <thead>
          <tr>
            <th
              className="py-1 pr-4 text-left font-medium"
              style={{ color: 'var(--martis-text-muted)', width: '40%' }}
            >
              {keyLabel ?? 'Key'}
            </th>
            <th
              className="py-1 text-left font-medium"
              style={{ color: 'var(--martis-text-muted)' }}
            >
              {valueLabel ?? 'Value'}
            </th>
          </tr>
        </thead>
        <tbody>
          {rows.map((row, i) => (
            <tr key={i} style={{ borderTop: '1px solid var(--martis-border)' }}>
              <td className="py-1.5 pr-4 font-mono text-xs" style={{ color: 'var(--martis-text)' }}>
                {row.key}
              </td>
              <td className="py-1.5 text-xs" style={{ color: 'var(--martis-text)' }}>
                {row.value}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}

// ---------------------------------------------------------------------------
// Input
// ---------------------------------------------------------------------------

export function KeyValueFieldInput({ field, value, onChange, error }: FieldInputProps) {
  const keyLabel = (field as Record<string, unknown>).keyLabel as string | undefined
  const valueLabel = (field as Record<string, unknown>).valueLabel as string | undefined
  const actionText = (field as Record<string, unknown>).actionText as string | undefined
  const editingKeysDisabled = (field as Record<string, unknown>).editingKeysDisabled as boolean | undefined
  const addingRowsDisabled = (field as Record<string, unknown>).addingRowsDisabled as boolean | undefined

  const [rows, setRows] = useState<KeyValueRow[]>(() => toRows(value))

  function emitChange(next: KeyValueRow[]) {
    setRows(next)
    onChange(next)
  }

  function addRow() {
    if (addingRowsDisabled || field.readonly) return
    emitChange([...rows, { key: '', value: '' }])
  }

  function removeRow(index: number) {
    if (field.readonly) return
    emitChange(rows.filter((_, i) => i !== index))
  }

  function updateKey(index: number, key: string) {
    if (editingKeysDisabled || field.readonly) return
    const next = rows.map((r, i) => (i === index ? { ...r, key } : r))
    emitChange(next)
  }

  function updateValue(index: number, val: string) {
    if (field.readonly) return
    const next = rows.map((r, i) => (i === index ? { ...r, value: val } : r))
    emitChange(next)
  }

  const inputBase: React.CSSProperties = {}

  return (
    <div className="flex flex-col gap-2">
      {/* Header */}
      <div className="flex gap-2 text-xs font-medium" style={{ color: 'var(--martis-text-muted)' }}>
        <div style={{ flex: 2 }}>{keyLabel ?? 'Key'}</div>
        <div style={{ flex: 3 }}>{valueLabel ?? 'Value'}</div>
        <div style={{ width: '1.75rem' }} />
      </div>

      {/* Rows */}
      {rows.map((row, i) => (
        <div key={i} className="flex gap-2 items-center">
          <input
            type="text"
            value={row.key}
            readOnly={!!editingKeysDisabled || field.readonly}
            onChange={(e) => updateKey(i, e.target.value)}
            placeholder={keyLabel ?? 'Key'}
            className="martis-input"
            style={{ ...inputBase, flex: 2, opacity: editingKeysDisabled ? 0.7 : 1 }}
          />
          <input
            type="text"
            value={row.value}
            readOnly={field.readonly}
            onChange={(e) => updateValue(i, e.target.value)}
            placeholder={valueLabel ?? 'Value'}
            className="martis-input"
            style={{ ...inputBase, flex: 3 }}
          />
          {!field.readonly && (
            <button
              type="button"
              onClick={() => removeRow(i)}
              title="Remove row"
              style={{ color: 'var(--martis-text-muted)', width: '1.75rem', flexShrink: 0 }}
              className="flex items-center justify-center hover:text-red-500 transition-colors"
            >
              <Trash size={14} />
            </button>
          )}
        </div>
      ))}

      {/* Add row button */}
      {!addingRowsDisabled && !field.readonly && (
        <button
          type="button"
          onClick={addRow}
          className="flex items-center gap-1.5 text-xs font-medium mt-1 transition-colors"
          style={{ color: 'var(--martis-accent)' }}
        >
          <Plus size={12} weight="bold" />
          {actionText ?? 'Add Row'}
        </button>
      )}

      {error && <small className="text-red-500">{error}</small>}
    </div>
  )
}
