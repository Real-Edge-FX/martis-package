import type { FieldDisplayProps, FieldInputProps } from './types'

export function ColorFieldDisplay({ value }: FieldDisplayProps) {
  if (value === null || value === undefined || value === '') {
    return <span className="martis-text-muted">\u2014</span>
  }

  const color = String(value)

  return (
    <div className="flex items-center gap-2">
      <span
        className="inline-block w-6 h-6 rounded shrink-0"
        style={{ backgroundColor: color, border: "1px solid var(--martis-border)" }}
      />
      <span className="font-mono text-sm" style={{ color: "var(--martis-text)" }}>{color}</span>
    </div>
  )
}

export function ColorFieldInput({ field, value, onChange, error }: FieldInputProps) {
  const color = value === null || value === undefined ? '#000000' : String(value)

  return (
    <div className="flex flex-col gap-1">
      <div className="flex items-center gap-3">
        <input
          id={field.attribute}
          name={field.attribute}
          type="color"
          value={color}
          disabled={field.readonly}
          onChange={(e) => onChange(e.target.value)}
          className="w-10 h-10 rounded cursor-pointer p-0"
          style={{ border: "1px solid var(--martis-border)" }}
        />
        <span className="text-sm font-mono" style={{ color: "var(--martis-text)" }}>{color}</span>
      </div>
      {error && <small className="text-red-500">{error}</small>}
    </div>
  )
}
