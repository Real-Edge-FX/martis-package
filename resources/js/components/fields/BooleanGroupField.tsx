import { useMemo } from 'react'
import type { FieldDisplayProps, FieldInputProps } from './types'

interface BooleanGroupSchema {
  options?: Record<string, string>
  labels?: Record<string, string>
  groups?: Record<string, string[]>
  hideFalseValues?: boolean
  hideTrueValues?: boolean
  noValueText?: string
  minChecked?: number
  maxChecked?: number
}

type Value = Record<string, boolean> | null | undefined

function labelFor(schema: BooleanGroupSchema, key: string): string {
  return schema.labels?.[key] ?? schema.options?.[key] ?? key
}

function countChecked(v: Value): number {
  if (!v || typeof v !== 'object') return 0
  return Object.values(v).filter(Boolean).length
}

// ─────────────────────────────────────────────────────────────────────
// Display — shows only the enabled flags as a tidy pill list, or the
// noValueText when everything is off and hideFalseValues is on.
// ─────────────────────────────────────────────────────────────────────
export function BooleanGroupFieldDisplay({ field, value }: FieldDisplayProps) {
  const schema = field as unknown as BooleanGroupSchema
  const v = (value ?? {}) as Record<string, boolean>
  const options = schema.options ?? {}

  const visible = Object.entries(options).filter(([key]) => {
    const on = !!v[key]
    if (on && schema.hideTrueValues) return false
    if (!on && schema.hideFalseValues) return false
    return true
  })

  if (visible.length === 0) {
    return <span className="martis-text-muted">{schema.noValueText ?? '—'}</span>
  }

  return (
    <div className="martis-boolgroup-display">
      {visible.map(([key]) => {
        const on = !!v[key]
        return (
          <span
            key={key}
            className={`martis-boolgroup-pill ${on ? 'is-on' : 'is-off'}`}
          >
            <span aria-hidden="true" className="martis-boolgroup-dot" />
            {labelFor(schema, key)}
          </span>
        )
      })}
    </div>
  )
}

// ─────────────────────────────────────────────────────────────────────
// Input — grouped sections (⭐) + live min/max counter (⭐).
// ─────────────────────────────────────────────────────────────────────
export function BooleanGroupFieldInput({ field, value, onChange, error }: FieldInputProps) {
  const schema = field as unknown as BooleanGroupSchema
  const options = schema.options ?? {}
  const groups = schema.groups

  const v = useMemo<Record<string, boolean>>(() => {
    const base: Record<string, boolean> = {}
    for (const key of Object.keys(options)) base[key] = false
    if (value && typeof value === 'object' && !Array.isArray(value)) {
      for (const [k, val] of Object.entries(value as Record<string, unknown>)) {
        base[k] = !!val
      }
    }
    return base
  }, [value, options])

  const checked = countChecked(v)
  const { minChecked, maxChecked } = schema

  const toggle = (key: string) => {
    const current = !!v[key]
    if (!current && maxChecked !== undefined && checked >= maxChecked) return
    const next = { ...v, [key]: !current }
    onChange(next)
  }

  const sections: Array<{ title: string | null; keys: string[] }> = groups
    ? Object.entries(groups).map(([title, keys]) => ({ title, keys: keys.filter((k) => k in options) }))
    : [{ title: null, keys: Object.keys(options) }]

  const counterState =
    minChecked !== undefined && checked < minChecked
      ? 'warn'
      : maxChecked !== undefined && checked === maxChecked
        ? 'warn'
        : 'ok'

  return (
    <div className={`martis-boolgroup${error ? ' has-error' : ''}`}>
      {(minChecked !== undefined || maxChecked !== undefined) && (
        <div className={`martis-boolgroup-counter state-${counterState}`}>
          {checked}
          {maxChecked !== undefined ? ` / ${maxChecked}` : ''}
          {minChecked !== undefined && ` (min ${minChecked})`}
        </div>
      )}
      {sections.map((section, idx) => (
        <fieldset key={idx} className="martis-boolgroup-section">
          {section.title && <legend className="martis-boolgroup-legend">{section.title}</legend>}
          <div className="martis-boolgroup-options">
            {section.keys.map((key) => {
              const on = !!v[key]
              const disabled =
                !on && maxChecked !== undefined && checked >= maxChecked
              return (
                <label
                  key={key}
                  className={`martis-boolgroup-option${disabled ? ' is-disabled' : ''}`}
                >
                  <input
                    type="checkbox"
                    checked={on}
                    disabled={disabled}
                    onChange={() => toggle(key)}
                  />
                  <span>{labelFor(schema, key)}</span>
                </label>
              )
            })}
          </div>
        </fieldset>
      ))}
      {error && <p className="martis-field-error">{error}</p>}
    </div>
  )
}
