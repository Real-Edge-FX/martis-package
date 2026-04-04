import type { FieldDisplayProps, FieldInputProps } from './types'

export function HiddenFieldDisplay(_props: FieldDisplayProps) {
  return null
}

export function HiddenFieldInput({ field, value, onChange }: FieldInputProps) {
  return (
    <input
      type="hidden"
      name={field.attribute}
      value={value != null ? String(value) : ''}
      onChange={(e) => onChange(e.target.value)}
    />
  )
}
