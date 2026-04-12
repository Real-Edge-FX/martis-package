import type { FieldDisplayProps, FieldInputProps } from './types'
import { Dropdown } from 'primereact/dropdown'

export function SelectFieldDisplay({ field, value }: FieldDisplayProps) {
  if (value === null || value === undefined || value === '') {
    return <span className="text-gray-400 dark:text-gray-500">—</span>
  }
  const opt = field.options?.find((o) => String(o.value) === String(value))
  return (
    <span className="inline-flex items-center rounded-full bg-indigo-100 px-2 py-0.5 text-xs font-medium text-indigo-800 dark:bg-indigo-900/30 dark:text-indigo-400">
      {opt ? opt.label : String(value)}
    </span>
  )
}

export function SelectFieldInput({ field, value, onChange, error }: FieldInputProps) {
  const options = field.options?.map((o) => ({ label: o.label, value: String(o.value) })) ?? []
  const currentValue = value === null || value === undefined ? '' : String(value)

  return (
    <div className="flex flex-col gap-1">
      <Dropdown
        inputId={field.attribute}
        name={field.attribute}
        value={currentValue}
        options={options}
        onChange={(e) => onChange(e.value as string)}
        disabled={field.readonly}
        invalid={!!error}
        placeholder={field.placeholder ?? '— Select —'}
        showClear={field.nullable}
        className="w-full"
      />
      {error && <small className="text-red-500">{error}</small>}
    </div>
  )
}
