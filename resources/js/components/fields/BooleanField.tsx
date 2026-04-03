import type { FieldDisplayProps, FieldInputProps } from './types'
import { InputSwitch } from 'primereact/inputswitch'

export function BooleanFieldDisplay({ value }: FieldDisplayProps) {
  const checked = Boolean(value)
  return (
    <span
      className={[
        'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium',
        checked
          ? 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400'
          : 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400',
      ].join(' ')}
    >
      {checked ? 'Sim' : 'Não'}
    </span>
  )
}

export function BooleanFieldInput({ field, value, onChange, error }: FieldInputProps) {
  const checked = Boolean(value)
  return (
    <div className="flex flex-col gap-1">
      <div className="flex items-center gap-3">
        <InputSwitch
          inputId={field.attribute}
          checked={checked}
          onChange={(e) => onChange(e.value)}
          disabled={field.readonly}
        />
        <label
          htmlFor={field.attribute}
          className="text-sm text-gray-700 dark:text-gray-300"
        >
          {checked ? 'Active' : 'Inactive'}
        </label>
      </div>
      {error && <small className="text-red-500">{error}</small>}
    </div>
  )
}
