import type { FieldDisplayProps, FieldInputProps } from './types'

export function NumberFieldDisplay({ value }: FieldDisplayProps) {
  if (value === null || value === undefined) {
    return <span className="text-gray-400 dark:text-gray-500">—</span>
  }
  return (
    <span className="font-mono text-gray-900 dark:text-white">{String(value)}</span>
  )
}

export function NumberFieldInput({ field, value, onChange, error }: FieldInputProps) {
  return (
    <div>
      <input
        type="number"
        id={field.attribute}
        name={field.attribute}
        value={value === null || value === undefined ? '' : String(value)}
        readOnly={field.readonly}
        required={field.required}
        onChange={(e) => onChange(e.target.value === '' ? null : Number(e.target.value))}
        className={[
          'block w-full rounded-md border px-3 py-2 text-sm shadow-sm font-mono',
          'bg-white text-gray-900 placeholder-gray-400',
          'dark:bg-gray-900 dark:text-white dark:placeholder-gray-500',
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
