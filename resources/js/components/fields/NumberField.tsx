import type { FieldDisplayProps, FieldInputProps } from './types'
import { InputNumber } from 'primereact/inputnumber'

export function NumberFieldDisplay({ value }: FieldDisplayProps) {
  if (value === null || value === undefined) {
    return <span className="text-gray-400 dark:text-gray-500">—</span>
  }
  return (
    <span className="font-mono text-gray-900 dark:text-white">{String(value)}</span>
  )
}

export function NumberFieldInput({ field, value, onChange, error }: FieldInputProps) {
  const numValue = value === null || value === undefined || value === '' ? null : Number(value)

  return (
    <div className="flex flex-col gap-1">
      <InputNumber
        inputId={field.attribute}
        name={field.attribute}
        value={numValue}
        onValueChange={(e) => onChange(e.value ?? null)}
        readOnly={field.readonly}
        required={field.required}
        invalid={!!error}
        disabled={field.readonly}
        className="w-full"
        inputClassName="w-full font-mono"
      />
      {error && <small className="text-red-500">{error}</small>}
    </div>
  )
}
