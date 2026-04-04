import type { FieldDisplayProps, FieldInputProps } from './types'
import { InputText } from 'primereact/inputtext'

export function TextFieldDisplay({ value }: FieldDisplayProps) {
  if (value === null || value === undefined || value === '') {
    return <span className="text-gray-400 dark:text-gray-500">—</span>
  }
  return <span className="text-gray-900 dark:text-white">{String(value)}</span>
}

export function TextFieldInput({ field, value, onChange, error }: FieldInputProps) {
  return (
    <div className="flex flex-col gap-1">
      <InputText
        id={field.attribute}
        name={field.attribute}
        type="text"
        value={value === null || value === undefined ? '' : String(value)}
        readOnly={field.readonly}
        required={field.required}
        onChange={(e) => onChange(e.target.value)}
        invalid={!!error}
        disabled={field.readonly}
        placeholder={field.placeholder ?? undefined}
        className="w-full"
      />
      {error && <small className="text-red-500">{error}</small>}
    </div>
  )
}
