import type { FieldDisplayProps, FieldInputProps } from './types'
import { Calendar } from 'primereact/calendar'
import { getCalendarLocale } from '@/lib/calendarLocale'
import { formatLocalDate, parseLocalDate } from '@/lib/localDate'
import { ClearButton } from '@/components/ClearButton'

function formatDate(value: unknown): string {
  // Parse as a local calendar date so the displayed day matches the stored
  // day in every timezone (a UTC parse shifts it west of UTC).
  const d = parseLocalDate(value)
  if (d === null) return typeof value === 'string' ? value : ''
  return d.toLocaleDateString()
}

export function DateFieldDisplay({ value }: FieldDisplayProps) {
  if (value === null || value === undefined || value === '') {
    return <span className="text-gray-400 dark:text-gray-500">—</span>
  }
  return (
    <span className="text-gray-900 dark:text-white">{formatDate(value)}</span>
  )
}

export function DateFieldInput({ field, value, onChange, error }: FieldInputProps) {
  const dateValue = parseLocalDate(value)
  const showClear = !!field.nullable && dateValue !== null && !field.readonly

  function handleChange(d: Date | null) {
    if (!d) {
      onChange(null)
    } else {
      // Format the LOCAL calendar date — d.toISOString() would shift the day
      // back in timezones east of UTC (pick the 20th, store the 19th).
      onChange(formatLocalDate(d))
    }
  }

  return (
    <div className="flex flex-col gap-1">
      <div className="relative">
        <Calendar
          inputId={field.attribute}
          name={field.attribute}
          value={dateValue}
          onChange={(e) => handleChange((e.value as Date | null) ?? null)}
          readOnlyInput={field.readonly}
          required={field.required}
          invalid={!!error}
          disabled={field.readonly}
          dateFormat="yy-mm-dd"
          locale={getCalendarLocale()}
          showIcon
          className="w-full"
          inputClassName="w-full"
          inputStyle={showClear ? { paddingRight: '2rem' } : undefined}
        />
        <ClearButton
          visible={showClear}
          onClick={() => onChange(null)}
          style={{ position: 'absolute', right: '3rem', top: '50%', transform: 'translateY(-50%)', zIndex: 1 }}
        />
      </div>
      {error && <small className="text-red-500">{error}</small>}
    </div>
  )
}
