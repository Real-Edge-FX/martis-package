import type { FieldDisplayProps, FieldInputProps } from './types'

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
    <div>
      <label className="flex cursor-pointer items-center gap-3">
        <button
          type="button"
          role="switch"
          aria-checked={checked}
          disabled={field.readonly}
          onClick={() => !field.readonly && onChange(!checked)}
          className={[
            'relative inline-flex h-6 w-11 items-center rounded-full transition-colors focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2',
            checked ? 'bg-blue-600' : 'bg-gray-200 dark:bg-gray-700',
            field.readonly ? 'cursor-not-allowed opacity-60' : 'cursor-pointer',
          ].join(' ')}
        >
          <span
            className={[
              'inline-block h-4 w-4 transform rounded-full bg-white shadow transition-transform',
              checked ? 'translate-x-6' : 'translate-x-1',
            ].join(' ')}
          />
        </button>
        <span className="text-sm text-gray-700 dark:text-gray-300">
          {checked ? 'Ativo' : 'Inativo'}
        </span>
      </label>
      {error && <p className="mt-1 text-xs text-red-500">{error}</p>}
    </div>
  )
}
