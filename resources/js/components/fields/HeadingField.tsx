import type { FieldDisplayProps, FieldInputProps } from './types'

export function HeadingFieldDisplay({ field }: FieldDisplayProps) {
  return (
    <div className="py-2">
      <h3 className="text-base font-semibold martis-text">{field.label}</h3>
      {field.content && (
        <p className="text-sm martis-text-muted mt-1">{field.content}</p>
      )}
    </div>
  )
}

export function HeadingFieldInput({ field }: FieldInputProps) {
  return (
    <div className="py-2">
      <h3 className="text-base font-semibold martis-text">{field.label}</h3>
      {field.content && (
        <p className="text-sm martis-text-muted mt-1">{field.content}</p>
      )}
    </div>
  )
}
