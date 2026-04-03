import type { FieldDisplayProps, FieldInputProps } from './types'
import { InputTextarea } from 'primereact/inputtextarea'

export function TextareaFieldDisplay({ value }: FieldDisplayProps) {
  if (value === null || value === undefined || value === '') {
    return <span className="text-gray-400 dark:text-gray-500">—</span>
  }
  return <span className="whitespace-pre-wrap text-gray-900 dark:text-white">{String(value)}</span>
}

export function TextareaFieldInput({ field, value, onChange, error }: FieldInputProps) {
  return (
    <div className="flex flex-col gap-1">
      <InputTextarea
        id={field.attribute}
        name={field.attribute}
        value={value === null || value === undefined ? '' : String(value)}
        readOnly={field.readonly}
        required={field.required}
        onChange={(e) => onChange(e.target.value)}
        invalid={!!error}
        disabled={field.readonly}
        rows={4}
        className="w-full"
        autoResize
      />
      {error && <small className="text-red-500">{error}</small>}
    </div>
  )
}
