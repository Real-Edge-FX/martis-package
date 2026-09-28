import type { FieldDisplayProps, FieldInputProps } from './types'
import { InputNumber } from 'primereact/inputnumber'
import { ClearButton } from '@/components/ClearButton'
import { toBcp47, useFormatLocale } from '@/lib/formatLocale'

interface CurrencyExt {
  currencyCode?: string
  currencySymbol?: string
  currencyName?: string
  currencyDecimals?: number
  /** Set by Currency::locale(); null or absent means the user's Martis locale. */
  locale?: string | null
  displayMode?: 'text' | 'badge' | 'badge_text'
  badgeColor?: string
  minorUnits?: boolean
  min?: number
  max?: number
  step?: number
}

function getExt(field: Record<string, unknown>): CurrencyExt {
  return field as unknown as CurrencyExt
}

const BADGE_COLORS: Record<string, string> = {
  green: 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400',
  blue: 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-400',
  red: 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400',
  yellow: 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-400',
  purple: 'bg-purple-100 text-purple-800 dark:bg-purple-900/30 dark:text-purple-400',
  indigo: 'bg-indigo-100 text-indigo-800 dark:bg-indigo-900/30 dark:text-indigo-400',
  gray: 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300',
}

function badgeClasses(color?: string): string {
  return BADGE_COLORS[color ?? 'indigo'] ?? BADGE_COLORS.indigo
}

function formatValue(val: number, ext: CurrencyExt, locale: string | undefined): string {
  const decimals = ext.currencyDecimals ?? 2
  const displayVal = ext.minorUnits ? val / Math.pow(10, decimals) : val

  try {
    return new Intl.NumberFormat(locale, {
      minimumFractionDigits: decimals,
      maximumFractionDigits: decimals,
    }).format(displayVal)
  } catch {
    return displayVal.toFixed(decimals)
  }
}

/** The field's own locale (Currency::locale()), else the user's Martis locale. */
function useCurrencyLocale(ext: CurrencyExt): string | undefined {
  const martisLocale = useFormatLocale()

  return toBcp47(ext.locale) ?? martisLocale
}

export function CurrencyFieldDisplay({ field, value }: FieldDisplayProps) {
  const ext = getExt(field as unknown as Record<string, unknown>)
  const locale = useCurrencyLocale(ext)
  if (value === null || value === undefined || value === '') {
    return <span className="text-gray-400 dark:text-gray-500">—</span>
  }

  const numVal = Number(value)
  if (Number.isNaN(numVal)) {
    return <span className="text-gray-400 dark:text-gray-500">—</span>
  }
  const formatted = formatValue(numVal, ext, locale)
  const symbol = ext.currencySymbol ?? ext.currencyCode ?? '$'
  const name = ext.currencyName ?? ext.currencyCode ?? 'USD'
  const mode = ext.displayMode ?? 'text'

  if (mode === 'badge') {
    return (
      <span className={"inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold " + badgeClasses(ext.badgeColor)}>
        {symbol} {formatted}
      </span>
    )
  }

  if (mode === 'badge_text') {
    return (
      <span className="inline-flex items-center gap-2">
        <span className={"inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold " + badgeClasses(ext.badgeColor)}>
          {symbol}
        </span>
        <span className="font-mono text-gray-900 dark:text-white">{formatted} {name}</span>
      </span>
    )
  }

  // Default: text mode
  return (
    <span className="font-mono text-gray-900 dark:text-white">
      {symbol} {formatted}
    </span>
  )
}

export function CurrencyFieldInput({ field, value, onChange, error }: FieldInputProps) {
  const ext = getExt(field as unknown as Record<string, unknown>)
  const locale = useCurrencyLocale(ext)
  const numValue = value === null || value === undefined || value === '' ? null : Number(value)
  const decimals = ext.currencyDecimals ?? 2
  const symbol = ext.currencySymbol ?? ext.currencyCode ?? '$'
  const showClear = !!field.nullable && numValue !== null && !field.readonly

  return (
    <div className="flex flex-col gap-1">
      <div className="relative">
        <InputNumber
          inputId={field.attribute}
          locale={locale}
          name={field.attribute}
          value={numValue}
          onValueChange={(e) => onChange(e.value ?? null)}
          readOnly={field.readonly}
          required={field.required}
          invalid={!!error}
          disabled={field.readonly}
          placeholder={field.placeholder ?? undefined}
          className="w-full"
          min={ext.min}
          max={ext.max}
          step={ext.step ?? (decimals > 0 ? parseFloat('0.' + '0'.repeat(decimals - 1) + '1') : 1)}
          minFractionDigits={decimals}
          maxFractionDigits={decimals}
          prefix={symbol + ' '}
          inputClassName="w-full font-mono"
          inputStyle={showClear ? { paddingRight: '2rem' } : undefined}
        />
        <ClearButton
          visible={showClear}
          onClick={() => onChange(null)}
          style={{ position: 'absolute', right: '0.5rem', top: '50%', transform: 'translateY(-50%)', zIndex: 1 }}
        />
      </div>
      {error && <small className="text-red-500">{error}</small>}
    </div>
  )
}
