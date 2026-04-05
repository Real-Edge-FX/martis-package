import type { FieldDisplayProps, FieldInputProps } from './types'
import { Dropdown } from 'primereact/dropdown'
import { useTranslation } from 'react-i18next'

interface CountryOption {
  label: string
  value: string
  flag: string
}

function getCountries(field: Record<string, unknown>): CountryOption[] {
  return (field.countries as CountryOption[] | undefined) ?? []
}

function getShowFlags(field: Record<string, unknown>): boolean {
  return (field.showFlags as boolean | undefined) ?? false
}

export function CountryFieldDisplay({ field, value }: FieldDisplayProps) {
  if (value === null || value === undefined || value === '') {
    return <span className="martis-text-muted">—</span>
  }

  const ext = field as unknown as Record<string, unknown>
  const countries = getCountries(ext)
  const showFlags = getShowFlags(ext)
  const code = String(value).toUpperCase()
  const country = countries.find((c) => c.value === code)
  const label = country?.label ?? code
  const flag = country?.flag ?? ''

  return (
    <span className="inline-flex items-center gap-1.5" style={{ color: "var(--martis-text)" }}>
      {showFlags && flag && <span className="text-base">{flag}</span>}
      <span>{label}</span>
    </span>
  )
}

export function CountryFieldInput({ field, value, onChange, error }: FieldInputProps) {
  const { t } = useTranslation('messages')
  const ext = field as unknown as Record<string, unknown>
  const countries = getCountries(ext)
  const showFlags = getShowFlags(ext)
  const currentValue = value === null || value === undefined ? '' : String(value).toUpperCase()

  const options = countries.map((c) => ({
    label: showFlags && c.flag ? `${c.flag} ${c.label}` : c.label,
    value: c.value,
  }))

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
        placeholder={field.placeholder ?? t('select')}
        showClear={field.nullable}
        filter
        filterPlaceholder={t('search')}
        emptyFilterMessage={t('no_results_found')}
        className="w-full"
      />
      {error && <small className="text-red-500">{error}</small>}
    </div>
  )
}
