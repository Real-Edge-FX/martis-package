import type { FieldDisplayProps, FieldInputProps } from './types'

export function ColorFieldDisplay({ value }: FieldDisplayProps) {
  if (value === null || value === undefined || value === '') {
    return <span className="text-gray-400 dark:text-gray-500">—</span>
  }

  const color = String(value)

  return (
    <div className="flex items-center gap-2">
      <span
        className="inline-block w-6 h-6 rounded border border-gray-300 dark:border-gray-600 shrink-0"
        style={{ backgroundColor: color }}
      />
      <span className="text-gray-900 dark:text-white font-mono text-sm">{color}</span>
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
          className="w-10 h-10 rounded border border-gray-300 dark:border-gray-600 cursor-pointer p-0"
        />
        <span className="text-sm text-gray-700 dark:text-gray-300 font-mono">{color}</span>
      </div>
      {error && <small className="text-red-500">{error}</small>}
    </div>
  )
}
