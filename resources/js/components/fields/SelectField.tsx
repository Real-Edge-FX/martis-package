import type { FieldDisplayProps, FieldInputProps } from './types'
import { Dropdown } from 'primereact/dropdown'
import { useTranslation } from 'react-i18next'
import { dropdownClearIconPt } from './dropdownHelpers'

export function SelectFieldDisplay({ field, value }: FieldDisplayProps) {
  if (value === null || value === undefined || value === '') {
    return <span className="text-gray-400 dark:text-gray-500">—</span>
  }
  const opt = field.options?.find((o) => String(o.value) === String(value))
  // PHP `Select::displayUsingLabels()` (default `true`) controls whether
  // the index/detail cell renders the option label or the raw stored
  // value. Falling back to the original label-resolution path when the
  // flag is missing keeps prior payloads working.
  const displayLabels = (field as Record<string, unknown>).displayLabels !== false
  const rendered = displayLabels && opt ? opt.label : String(value)
  return (
    <span className="martis-badge martis-badge-neutral">
      {rendered}
    </span>
  )
}

export function SelectFieldInput({ field, value, onChange, error }: FieldInputProps) {
  const { t } = useTranslation('messages')
  const options = field.options?.map((o) => ({ label: o.label, value: String(o.value) })) ?? []
  // Pass `null` (not '') when empty so PrimeReact's own `value != null` guard
  // hides the clear (X) icon on an empty select — an empty select has nothing
  // to clear. Coercing to '' made `showClear` fire on the placeholder state.
  // Guard the rare case of a real option whose value is literally '': only
  // treat '' as "empty" when no such option exists, so a genuinely-selected
  // empty-string option still highlights.
  const hasEmptyOption = options.some((o) => o.value === '')
  const isEmpty = value === null || value === undefined || (value === '' && !hasEmptyOption)
  const currentValue = isEmpty ? null : String(value)
  const clearTip = t('clear', { defaultValue: 'Clear' })
  const selectPlaceholder = field.placeholder ?? t('select', { defaultValue: 'Select…' })
  // Opt into the compact filter-dropdown look (used by native resource filters)
  // via `field.variant === 'filter'`, and allow an extra passthrough className.
  // Applied as a prop so PrimeReact re-applies it on every re-render (an
  // imperative classList.add is dropped when PrimeReact rewrites the class).
  const dropdownClass = [
    'w-full',
    field.variant === 'filter' ? 'martis-filter-dropdown' : '',
    field.className ?? '',
  ].filter(Boolean).join(' ')

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
        placeholder={selectPlaceholder}
        showClear={field.nullable}
        pt={{
          clearIcon: dropdownClearIconPt(clearTip),
        }}
        className={dropdownClass}
      />
      {error && <small className="text-red-500">{error}</small>}
    </div>
  )
}
