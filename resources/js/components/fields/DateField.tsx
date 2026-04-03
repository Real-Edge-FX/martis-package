import type { FieldDisplayProps, FieldInputProps } from './types'

function formatDate(value: unknown): string {
  if (!value || typeof value !== 'string') return ''
  const d = new Date(value)
  return isNaN(d.getTime()) ? String(value) : d.toLocaleDateString('pt-BR')
}

function toInputDate(value: unknown): string {
  if (!value || typeof value !== 'string') return ''
  const d = new Date(value)
  if (isNaN(d.getTime())) return ''
  return d.toISOString().split('T')[0]
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
  return (
    <div>
      <input
        type="date"
        id={field.attribute}
        name={field.attribute}
        value={toInputDate(value)}
        readOnly={field.readonly}
        required={field.required}
        onChange={(e) => onChange(e.target.value || null)}
        className={[
          'block w-full rounded-md border px-3 py-2 text-sm shadow-sm',
          'bg-white text-gray-900',
          'dark:bg-gray-900 dark:text-white',
          error
            ? 'border-red-500 focus:ring-red-500'
            : 'border-gray-300 dark:border-gray-700 focus:border-blue-500 focus:ring-blue-500',
          'focus:outline-none focus:ring-1',
          field.readonly ? 'cursor-not-allowed opacity-60' : '',
        ]
          .filter(Boolean)
          .join(' ')}
      />
      {error && <p className="mt-1 text-xs text-red-500">{error}</p>}
    </div>
  )
}
