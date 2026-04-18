import { useMemo } from 'react'
import { Checkbox } from 'primereact/checkbox'
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
// Input — grouped sections (⭐) + live min/max counter (⭐)
// using the package-standard PrimeReact Checkbox chrome.
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
  const total = Object.keys(options).length
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

  const counterLabel = (() => {
    if (maxChecked !== undefined) {
      return `${checked} / ${maxChecked}` + (minChecked !== undefined && minChecked > 0 ? ` · min ${minChecked}` : '')
    }
    if (minChecked !== undefined) {
      return `${checked} / ${total}` + ` · min ${minChecked}`
    }
    return null
  })()

  return (
    <div className={`martis-boolgroup${error ? ' has-error' : ''}`}>
      {counterLabel !== null && (
        <div className={`martis-boolgroup-counter state-${counterState}`}>
          <span className="martis-boolgroup-counter-value">{counterLabel}</span>
        </div>
      )}
      <div className="martis-boolgroup-stack">
        {sections.map((section, idx) => (
          <section key={idx} className="martis-boolgroup-section">
            {section.title && (
              <header className="martis-boolgroup-legend">
                <span>{section.title}</span>
                <span className="martis-boolgroup-section-count">
                  {section.keys.filter((k) => v[k]).length} / {section.keys.length}
                </span>
              </header>
            )}
            <div className="martis-boolgroup-options">
              {section.keys.map((key) => {
                const on = !!v[key]
                const disabled =
                  !on && maxChecked !== undefined && checked >= maxChecked
                const inputId = `mbg-${key.replace(/[^a-z0-9]/gi, '-')}`
                return (
                  <label
                    key={key}
                    htmlFor={inputId}
                    className={`martis-boolgroup-option${on ? ' is-on' : ''}${disabled ? ' is-disabled' : ''}`}
                  >
                    <Checkbox
                      inputId={inputId}
                      checked={on}
                      disabled={disabled}
                      onChange={() => toggle(key)}
                    />
                    <span className="martis-boolgroup-option-label">{labelFor(schema, key)}</span>
                  </label>
                )
              })}
            </div>
          </section>
        ))}
      </div>
      {error && <p className="martis-field-error">{error}</p>}
    </div>
  )
}
