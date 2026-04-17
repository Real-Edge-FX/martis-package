import type { FieldDisplayProps, FieldInputProps } from './types'
import { InputText } from 'primereact/inputtext'
import { ClearButton } from '@/components/ClearButton'

export function UrlFieldDisplay({ field, value }: FieldDisplayProps) {
  if (value === null || value === undefined || value === '') {
    return <span className="text-gray-400 dark:text-gray-500">—</span>
  }

  const url = String(value)
  const displayText = (field as Record<string, unknown>).displayText as string | undefined

  return (
    <a
      href={url}
      target="_blank"
      rel="noopener noreferrer"
      className="text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300 underline"
    >
      {displayText || url}
    </a>
  )
}

export function UrlFieldInput({ field, value, onChange, error }: FieldInputProps) {
  const stringValue = value === null || value === undefined ? '' : String(value)
  const showClear = !!field.nullable && stringValue !== '' && !field.readonly

  return (
    <div className="flex flex-col gap-1">
      <div className="relative">
        <InputText
          id={field.attribute}
          name={field.attribute}
          type="url"
          value={stringValue}
          readOnly={field.readonly}
          required={field.required}
          onChange={(e) => onChange(e.target.value)}
          invalid={!!error}
          disabled={field.readonly}
          placeholder={field.placeholder ?? 'https://'}
          className="w-full"
          style={showClear ? { paddingRight: '2rem' } : undefined}
        />
        <ClearButton
          visible={showClear}
          onClick={() => onChange(null)}
          style={{ position: 'absolute', right: '0.5rem', top: '50%', transform: 'translateY(-50%)' }}
        />
      </div>
      {error && <small className="text-red-500">{error}</small>}
    </div>
  )
}
