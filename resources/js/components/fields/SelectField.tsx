import type { FieldDisplayProps, FieldInputProps } from './types'

export function SelectFieldDisplay({ field, value }: FieldDisplayProps) {
  if (value === null || value === undefined || value === '') {
    return <span className="text-gray-400 dark:text-gray-500">—</span>
  }
  const opt = field.options?.find((o) => String(o.value) === String(value))
  return (
    <span className="inline-flex items-center rounded-full bg-blue-100 px-2 py-0.5 text-xs font-medium text-blue-800 dark:bg-blue-900/30 dark:text-blue-400">
      {opt ? opt.label : String(value)}
    </span>
  )
}

export function SelectFieldInput({ field, value, onChange, error }: FieldInputProps) {
  return (
    <div>
      <select
        id={field.attribute}
        name={field.attribute}
        value={value === null || value === undefined ? '' : String(value)}
        disabled={field.readonly}
        required={field.required}
        onChange={(e) => onChange(e.target.value)}
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
      >
        {field.nullable && <option value="">— Selecione —</option>}
        {field.options?.map((opt) => (
          <option key={String(opt.value)} value={String(opt.value)}>
            {opt.label}
          </option>
        ))}
      </select>
      {error && <p className="mt-1 text-xs text-red-500">{error}</p>}
    </div>
  )
}
