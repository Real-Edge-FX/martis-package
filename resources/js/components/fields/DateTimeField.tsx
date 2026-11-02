import { useState } from 'react'
import type { KeyboardEvent } from 'react'
import type { FieldDisplayProps, FieldInputProps } from './types'
import { Calendar } from 'primereact/calendar'
import { ChevronUpIcon } from 'primereact/icons/chevronup'
import { ChevronDownIcon } from 'primereact/icons/chevrondown'
import { getCalendarLocale } from '@/lib/calendarLocale'
import { ClearButton } from '@/components/ClearButton'
import { useFormatLocale } from '@/lib/formatLocale'

function formatDateTime(value: unknown, locale: string | undefined): string {
  if (!value || typeof value !== 'string') return ''
  const d = new Date(value)
  return isNaN(d.getTime()) ? String(value) : d.toLocaleString(locale)
}

function toDate(value: unknown): Date | null {
  if (!value || typeof value !== 'string') return null
  const d = new Date(value)
  return isNaN(d.getTime()) ? null : d
}

function pad(n: number): string {
  return n < 10 ? `0${n}` : String(n)
}

interface TimeSegmentProps {
  value: number
  max: number
  label: string
  disabled?: boolean
  onCommit: (value: number) => void
}

/**
 * One hour or minute segment of the time picker. PrimeReact's own picker
 * only has increment buttons; this one also accepts typed digits and the
 * arrow keys.
 */
function TimeSegment({ value, max, label, disabled, onCommit }: TimeSegmentProps) {
  const [draft, setDraft] = useState<string | null>(null)

  function step(delta: number) {
    setDraft(null)
    onCommit((value + delta + max + 1) % (max + 1))
  }

  function handleChange(raw: string) {
    const digits = raw.replace(/\D/g, '').slice(-2)
    setDraft(digits)
    if (digits !== '' && Number(digits) <= max) onCommit(Number(digits))
  }

  function handleKeyDown(e: KeyboardEvent<HTMLInputElement>) {
    if (e.key === 'ArrowUp' || e.key === 'ArrowDown') {
      e.preventDefault()
      step(e.key === 'ArrowUp' ? 1 : -1)
    } else if (e.key === 'Enter') {
      e.preventDefault()
      setDraft(null)
    }
  }

  return (
    <div className={max === 23 ? 'p-hour-picker' : 'p-minute-picker'}>
      <button type="button" className="p-link" aria-label={`${label} +1`} disabled={disabled} onClick={() => step(1)}>
        <ChevronUpIcon />
      </button>
      <input
        type="text"
        inputMode="numeric"
        aria-label={label}
        value={draft ?? pad(value)}
        disabled={disabled}
        onFocus={(e) => e.target.select()}
        onChange={(e) => handleChange(e.target.value)}
        onKeyDown={handleKeyDown}
        onBlur={() => setDraft(null)}
        style={{
          width: '2.5ch',
          fontSize: '1.25rem',
          textAlign: 'center',
          background: 'transparent',
          border: 0,
          color: 'inherit',
          padding: 0,
        }}
      />
      <button type="button" className="p-link" aria-label={`${label} -1`} disabled={disabled} onClick={() => step(-1)}>
        <ChevronDownIcon />
      </button>
    </div>
  )
}

export function DateTimeFieldDisplay({ value }: FieldDisplayProps) {
  const locale = useFormatLocale()
  if (value === null || value === undefined || value === '') {
    return <span className="text-gray-400 dark:text-gray-500">—</span>
  }
  return (
    <span className="text-gray-900 dark:text-white">{formatDateTime(value, locale)}</span>
  )
}

export function DateTimeFieldInput({ field, value, onChange, error }: FieldInputProps) {
  const dateValue = toDate(value)
  const showClear = !!field.nullable && dateValue !== null && !field.readonly
  const shown = dateValue ?? new Date()

  function handleChange(d: Date | null) {
    if (!d) {
      onChange(null)
    } else {
      onChange(d.toISOString())
    }
  }

  function setTime(hours: number, minutes: number) {
    const next = dateValue ? new Date(dateValue) : new Date()
    next.setHours(hours, minutes, 0, 0)
    handleChange(next)
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
          showTime
          hourFormat="24"
          showIcon
          className="w-full"
          inputClassName="w-full"
          inputStyle={showClear ? { paddingRight: '2rem' } : undefined}
          // The built-in time picker is hidden and replaced, in the footer,
          // by one whose hour and minute can be typed.
          pt={{ timePicker: { style: { display: 'none' } } }}
          footerTemplate={() => (
            <div className="p-timepicker">
              <TimeSegment
                value={shown.getHours()}
                max={23}
                label="Hour"
                disabled={field.readonly}
                onCommit={(h) => setTime(h, shown.getMinutes())}
              />
              <div className="p-separator">
                <span>:</span>
              </div>
              <TimeSegment
                value={shown.getMinutes()}
                max={59}
                label="Minute"
                disabled={field.readonly}
                onCommit={(m) => setTime(shown.getHours(), m)}
              />
            </div>
          )}
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
