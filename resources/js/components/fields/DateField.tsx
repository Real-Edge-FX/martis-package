import type { FieldDisplayProps, FieldInputProps } from './types'
import { Calendar } from 'primereact/calendar'

function formatDate(value: unknown): string {
  if (!value || typeof value !== 'string') return ''
  const d = new Date(value)
  return isNaN(d.getTime()) ? String(value) : d.toLocaleDateString()
}

function toDate(value: unknown): Date | null {
  if (!value || typeof value !== 'string') return null
  const d = new Date(value)
  return isNaN(d.getTime()) ? null : d
}

export function DateFieldDisplay({ value }: FieldDisplayProps) {
  if (value === null || value === undefined || value === '') {
    return <span className="text-gray-400 dark:text-gray-500">—</span>
  }
  return (
    <span className="text-gray-900 dark:text-white">{formatDate(value)}</span>
  )
}

export function DateFieldInput({ field, value, onChange, error }: FieldInputProps) {
  const dateValue = toDate(value)

  function handleChange(d: Date | null) {
    if (!d) {
      onChange(null)
    } else {
      onChange(d.toISOString().split('T')[0])
    }
  }

  return (
    <div className="flex flex-col gap-1">
      <Calendar
        inputId={field.attribute}
        name={field.attribute}
        value={dateValue}
        onChange={(e) => handleChange((e.value as Date | null) ?? null)}
        readOnlyInput={field.readonly}
        required={field.required}
        invalid={!!error}
        disabled={field.readonly}
        dateFormat="yy-mm-dd"
        showIcon
        className="w-full"
        inputClassName="w-full"
      />
      {error && <small className="text-red-500">{error}</small>}
    </div>
  )
}
