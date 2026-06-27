import { useState, useEffect, useRef } from 'react'
import { Dropdown } from 'primereact/dropdown'
import { Calendar } from 'primereact/calendar'
import { getCalendarLocale } from '@/lib/calendarLocale'
import { InputSwitch } from 'primereact/inputswitch'
import { FunnelIcon, XIcon } from '@phosphor-icons/react'
import { useTranslation } from 'react-i18next'
import type { FilterDefinition, ActiveFilters } from '@/types'

interface FilterPanelProps {
  filters: FilterDefinition[]
  value: ActiveFilters
  onChange: (filters: ActiveFilters) => void
  /** Optional content rendered inline before the filter toggle button. */
  prefix?: React.ReactNode
  /** Optional content pinned to the right of the toggle row (e.g. Bulk Actions).
      Stays on the toggle row even when the filter panel is open, so the
      expandable box below can claim the full width. */
  rightSlot?: React.ReactNode
  /** Controlled open state. When omitted the panel manages its own state.
   *  When supplied, the parent owns the toggle and can persist it (the
   *  ResourceIndex page does this via sticky views so the open / closed
   *  state is restored per-resource). */
  open?: boolean
  /** Called whenever the panel toggles. Required when `open` is supplied. */
  onOpenChange?: (open: boolean) => void
}

/**
 * Compute default filter values from schema definitions.
 * Called once on initial load to pre-populate filters that have defaults.
 */
function computeDefaults(filters: FilterDefinition[]): ActiveFilters {
  const defaults: ActiveFilters = {}
  for (const filter of filters) {
    if (filter.default !== null && filter.default !== undefined && filter.default !== '') {
      defaults[filter.uriKey] = filter.default
    }
  }
  return defaults
}

const calendarLocale = getCalendarLocale()

export function FilterPanel({ filters, value, onChange, prefix, rightSlot, open: controlledOpen, onOpenChange }: FilterPanelProps) {
  const { t } = useTranslation('resources')
  // Dual-mode open state — when the parent supplies `open` + `onOpenChange`
  // we run controlled (used by ResourceIndex so the open/closed flag
  // can persist per-resource via sticky views). Otherwise fall back to
  // the local-state behaviour for any callers that don't care.
  const [uncontrolledOpen, setUncontrolledOpen] = useState(false)
  const open = controlledOpen ?? uncontrolledOpen
  const setOpen = (next: boolean): void => {
    if (onOpenChange) onOpenChange(next)
    if (controlledOpen === undefined) setUncontrolledOpen(next)
  }
  const defaultsApplied = useRef(false)

  // Differential 3: Apply default values on initial load
  useEffect(() => {
    if (defaultsApplied.current) return
    defaultsApplied.current = true

    const defaults = computeDefaults(filters)
    if (Object.keys(defaults).length > 0 && Object.keys(value).length === 0) {
      onChange(defaults)
    }
  }, [filters]) // eslint-disable-line react-hooks/exhaustive-deps

  // Reset defaults flag when filters change (e.g. resource navigation)
  useEffect(() => {
    defaultsApplied.current = false
  }, [filters])

  const activeCount = Object.values(value).filter(
    (v) => v !== null && v !== undefined && v !== '',
  ).length

  const handleChange = (uriKey: string, filterValue: unknown) => {
    const next = { ...value, [uriKey]: filterValue }

    if (filterValue === null || filterValue === undefined || filterValue === '') {
      delete next[uriKey]
    }

    onChange(next)
  }

  const handleClearSingle = (uriKey: string) => {
    const next = { ...value }
    delete next[uriKey]
    onChange(next)
  }

  if (filters.length === 0) return null

  // Build the active filter entries for pills
  const activeEntries = filters
    .filter((f) => {
      const v = value[f.uriKey]
      return v !== null && v !== undefined && v !== ''
    })
    .map((f) => ({
      filter: f,
      displayValue: formatFilterValue(f, value[f.uriKey]),
    }))

  return (
    <div>
      {/* Toggle button row — toggle + pills on the left, Bulk Actions (or
          any other rightSlot) pinned to the right, even when the filter
          box below is expanded. */}
      <div className="flex items-center justify-between gap-3">
        <div className="flex items-center gap-2 flex-wrap">
          {prefix}
          <button
          type="button"
          data-testid="filter-toggle"
          onClick={() => setOpen(!open)}
          className="inline-flex items-center gap-2 rounded-md px-3 py-1.5 text-sm font-medium transition-colors"
          style={{
            backgroundColor: 'transparent',
            color: 'var(--martis-text-muted)',
            border: 'none',
          }}
        >
          <FunnelIcon size={14} weight={activeCount > 0 ? 'fill' : 'regular'} style={activeCount > 0 ? { color: 'var(--martis-accent)' } : undefined} />
          {t('filters', 'Filters')}
          {activeCount > 0 && (
            <span
              className="inline-flex items-center justify-center rounded-full text-xs font-bold"
              style={{
                width: 18,
                height: 18,
                backgroundColor: 'var(--martis-accent)',
                color: '#fff',
              }}
            >
              {activeCount}
            </span>
          )}
        </button>

        {/* Differential 2: Active filter pills — visible even when panel is closed */}
        {activeEntries.map(({ filter, displayValue }) => (
          <span key={filter.uriKey} className="martis-filter-chip">
            <span className="martis-filter-chip-label">{filter.name}:</span>
            <span className="martis-filter-chip-value">{displayValue}</span>
            <button
              type="button"
              onClick={() => handleClearSingle(filter.uriKey)}
              className={`martis-filter-chip-x martis-filter-pill-clear-${filter.uriKey}`}
              aria-label={`${t('clear_filter', 'Clear filter')}: ${filter.name}`}
              data-pr-tooltip={t('clear_filter', 'Clear filter')}
              data-pr-position="top"
            >
              <XIcon size={10} weight="bold" />
            </button>
          </span>
        ))}
        </div>
        {rightSlot}
      </div>

      {/* Filter panel (collapsible) */}
      {open && (
        <div
          className="mt-2 rounded-lg p-4"
          style={{
            backgroundColor: 'var(--martis-surface)',
            border: '1px solid var(--martis-border)',
          }}
        >
          <div className="grid gap-4" style={{ gridTemplateColumns: 'repeat(12, minmax(0, 1fr))' }}>
            {filters.map((filter) => {
              const colSpan = filter.span ?? (filter.filterType === 'date-range' ? 6 : 3)
              return (
              <div key={filter.uriKey} style={{ gridColumn: `span ${colSpan}` }}>
                <label
                  className="mb-1 block text-xs font-medium"
                  style={{ color: 'var(--martis-text-muted)' }}
                >
                  {filter.name}
                </label>
                <FilterInput
                  filter={filter}
                  value={value[filter.uriKey]}
                  onChange={(v) => handleChange(filter.uriKey, v)}
                />
              </div>
              )
            })}
          </div>

        </div>
      )}
    </div>
  )
}

// ---------------------------------------------------------------------------
// Format active filter value for pill display
// ---------------------------------------------------------------------------

function formatFilterValue(filter: FilterDefinition, rawValue: unknown): string {
  if (rawValue === null || rawValue === undefined) return ''

  switch (filter.filterType) {
    case 'select': {
      const opt = filter.options.find((o) => String(o.value) === String(rawValue))
      return opt ? opt.label : String(rawValue)
    }
    case 'boolean': {
      if (typeof rawValue !== 'object' || rawValue === null) return ''
      const entries = Object.entries(rawValue as Record<string, boolean>).filter(([, v]) => v)
      const labels = entries.map(([key]) => {
        const opt = filter.options.find((o) => String(o.value) === key)
        return opt ? opt.label : key
      })
      return labels.join(', ')
    }
    case 'date':
      return String(rawValue)
    case 'date-range': {
      const range = rawValue as { from?: string; to?: string }
      if (range.from && range.to) return `${range.from} — ${range.to}`
      if (range.from) return `≥ ${range.from}`
      if (range.to) return `≤ ${range.to}`
      return ''
    }
    default:
      return String(rawValue)
  }
}

// ---------------------------------------------------------------------------
// Date helper — format as YYYY-MM-DD using local timezone (not UTC)
// ---------------------------------------------------------------------------

function toLocalDateString(date: Date): string {
  const y = date.getFullYear()
  const m = String(date.getMonth() + 1).padStart(2, '0')
  const d = String(date.getDate()).padStart(2, '0')
  return `${y}-${m}-${d}`
}

/** Parse a YYYY-MM-DD string as a local date (not UTC). */
function parseLocalDate(str: string): Date {
  const [y, m, d] = str.split('-').map(Number)
  return new Date(y, m - 1, d)
}

// ---------------------------------------------------------------------------
// Individual filter input renderer
// ---------------------------------------------------------------------------

interface FilterInputProps {
  filter: FilterDefinition
  value: unknown
  onChange: (value: unknown) => void
}

function FilterInput({ filter, value, onChange }: FilterInputProps) {
  const { t } = useTranslation('resources')
  const wrapperRef = useRef<HTMLDivElement>(null)

  // Add data-pr-tooltip to PrimeReact's internal clear icon so the
  // global MartisTooltip can pick it up on hover.
  useEffect(() => {
    const el = wrapperRef.current
    if (!el) return
    const clearIcon = el.querySelector('.p-dropdown-clear-icon')
    if (clearIcon) {
      clearIcon.setAttribute('data-pr-tooltip', t('clear_filter', 'Clear filter'))
      clearIcon.setAttribute('data-pr-position', 'top')
    }
  })

  switch (filter.filterType) {
    case 'select': {
      const hasGroups = filter.options.some((o) => o.group)

      const dropdownProps = hasGroups
        ? {
            options: groupOptions(filter.options),
            optionLabel: 'label' as const,
            optionValue: 'value' as const,
            optionGroupLabel: 'label',
            optionGroupChildren: 'items',
          }
        : {
            options: filter.options.map((o) => ({ label: o.label, value: o.value })),
          }

      return (
        <div ref={wrapperRef}>
          <Dropdown
            value={value ?? null}
            {...dropdownProps}
            onChange={(e) => onChange(e.value)}
            showClear
            placeholder={filter.name}
            filter={!!filter.meta?.searchable}
            className="w-full martis-filter-dropdown"
          />
        </div>
      )
    }

    case 'boolean':
      return (
        <div className="flex flex-col gap-2 pt-1">
          {filter.options.map((option) => {
            const boolMap = (value ?? {}) as Record<string, boolean>
            const checked = !!boolMap[String(option.value)]
            return (
              <div key={String(option.value)} className="flex items-center gap-2">
                <InputSwitch
                  checked={checked}
                  onChange={(e) => {
                    const next = { ...boolMap, [String(option.value)]: e.value }
                    const hasAny = Object.values(next).some(Boolean)
                    onChange(hasAny ? next : null)
                  }}
                />
                <span className="text-sm" style={{ color: 'var(--martis-text)' }}>
                  {option.label}
                </span>
              </div>
            )
          })}
        </div>
      )

    case 'date':
      return (
        <Calendar
          value={value ? parseLocalDate(value as string) : null}
          onChange={(e) => {
            if (e.value instanceof Date) {
              const iso = toLocalDateString(e.value)
              onChange(iso)
            } else {
              onChange(null)
            }
          }}
          showIcon
          showButtonBar
          locale={calendarLocale}
          dateFormat="yy-mm-dd"
          placeholder={filter.name}
          className="w-full"
          inputClassName="text-sm"
        />
      )

    case 'date-range': {
      const range = (value ?? {}) as { from?: string; to?: string }
      return (
        <div className="flex gap-2">
          <Calendar
            value={range.from ? parseLocalDate(range.from) : null}
            onChange={(e) => {
              const from = e.value instanceof Date ? toLocalDateString(e.value) : undefined
              const next = { ...range, from }
              if (!next.from && !next.to) {
                onChange(null)
              } else {
                onChange(next)
              }
            }}
            showIcon
            showButtonBar
            locale={calendarLocale}
            dateFormat="yy-mm-dd"
            placeholder={t('filter_from', 'From')}
            className="flex-1"
            inputClassName="text-sm"
          />
          <Calendar
            value={range.to ? parseLocalDate(range.to) : null}
            onChange={(e) => {
              const to = e.value instanceof Date ? toLocalDateString(e.value) : undefined
              const next = { ...range, to }
              if (!next.from && !next.to) {
                onChange(null)
              } else {
                onChange(next)
              }
            }}
            showIcon
            showButtonBar
            locale={calendarLocale}
            dateFormat="yy-mm-dd"
            placeholder={t('filter_to', 'To')}
            className="flex-1"
            inputClassName="text-sm"
          />
        </div>
      )
    }

    default:
      return null
  }
}

// ---------------------------------------------------------------------------
// Group flat options into PrimeReact optionGroup format
// ---------------------------------------------------------------------------

function groupOptions(
  options: Array<{ label: string; value: string | number | boolean; group?: string }>,
): Array<{ label: string; items: Array<{ label: string; value: string | number | boolean }> }> {
  const groups = new Map<
    string,
    Array<{ label: string; value: string | number | boolean }>
  >()

  for (const opt of options) {
    const groupName = opt.group ?? ''
    if (!groups.has(groupName)) {
      groups.set(groupName, [])
    }
    groups.get(groupName)!.push({ label: opt.label, value: opt.value })
  }

  return Array.from(groups.entries()).map(([label, items]) => ({
    label,
    items,
  }))
}
