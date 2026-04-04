import type { FieldDisplayProps, FieldInputProps } from './types'

export function IdFieldDisplay({ value }: FieldDisplayProps) {
  if (value === null || value === undefined || value === '') {
    return <span className="text-gray-400 dark:text-gray-500">—</span>
  }
  return (
    <span className="text-xs font-mono text-gray-500 dark:text-gray-400">
      #{String(value)}
    </span>
  )
}

// ID fields are hidden from forms, but provide a no-op input for safety
export function IdFieldInput({ value }: FieldInputProps) {
  return (
    <span className="text-sm text-gray-500 dark:text-gray-400">
      #{String(value ?? '')}
    </span>
  )
}
