import { useEffect, useMemo, useState } from 'react'
import { Dropdown } from 'primereact/dropdown'
import { useTranslation } from 'react-i18next'
import { GlobeHemisphereEastIcon, CrosshairIcon } from '@phosphor-icons/react'
import type { FieldDisplayProps, FieldInputProps } from './types'
import { dropdownClearIconPt } from './dropdownHelpers'

type GroupedZones = Record<string, string[]>

// -----------------------------------------------------------------------------
// Helpers
// -----------------------------------------------------------------------------

function formatOffset(zone: string, now: Date): string {
  try {
    const formatter = new Intl.DateTimeFormat('en-US', {
      timeZone: zone,
      timeZoneName: 'shortOffset',
    })
    const parts = formatter.formatToParts(now)
    const offset = parts.find((p) => p.type === 'timeZoneName')?.value ?? ''
    // shortOffset returns things like "GMT+1" — normalise to "+01:00" for sort.
    const match = offset.match(/([+-])(\d{1,2})(?::?(\d{2}))?/)
    if (!match) return offset || ''
    const sign = match[1]
    const hh = match[2].padStart(2, '0')
    const mm = (match[3] ?? '00').padStart(2, '0')
    return `${sign}${hh}:${mm}`
  } catch {
    return ''
  }
}

function formatLocalTime(zone: string, now: Date): string {
  try {
    return new Intl.DateTimeFormat('en-GB', {
      timeZone: zone,
      hour: '2-digit',
      minute: '2-digit',
      hour12: false,
    }).format(now)
  } catch {
    return ''
  }
}

function detectBrowserTimezone(): string | null {
  try {
    return Intl.DateTimeFormat().resolvedOptions().timeZone || null
  } catch {
    return null
  }
}

// -----------------------------------------------------------------------------
// Display
// -----------------------------------------------------------------------------

export function TimezoneFieldDisplay({ value }: FieldDisplayProps) {
  if (value === null || value === undefined || value === '') {
    return <span className="text-gray-400 dark:text-gray-500">—</span>
  }
  const now = new Date()
  const zone = String(value)
  const offset = formatOffset(zone, now)
  return (
    <span className="inline-flex items-center gap-1.5" style={{ color: 'var(--martis-text)' }}>
      <GlobeHemisphereEastIcon size={14} style={{ color: 'var(--martis-text-muted)' }} />
      <span>{zone}</span>
      {offset && (
        <span className="text-xs" style={{ color: 'var(--martis-text-muted)' }}>
          ({offset})
        </span>
      )}
    </span>
  )
}

// -----------------------------------------------------------------------------
// Input
// -----------------------------------------------------------------------------

export function TimezoneFieldInput({ field, value, onChange, error }: FieldInputProps) {
  const { t } = useTranslation('messages')
  const grouped = ((field as unknown as { options?: GroupedZones }).options ?? {}) as GroupedZones

  // ⭐ D1 — Tick once a minute so the current-time label next to each option
  // stays accurate while the dropdown is open.
  const [tick, setTick] = useState(0)
  useEffect(() => {
    const id = setInterval(() => setTick((x) => x + 1), 60_000)
    return () => clearInterval(id)
  }, [])

  const now = useMemo(() => new Date(), [tick])

  // ⭐ D3 — Grouped options structured for PrimeReact Dropdown
  // (`optionGroupLabel="groupLabel"` + `optionGroupChildren="items"`).
  // NOTE: the group-label field must NOT be named `group` — PrimeReact uses
  // a boolean `group` flag internally and overwrites it on the item object,
  // so our label would come through as `true` and render nothing.
  type Option = { label: string; value: string; offset: string }
  type Group = { groupLabel: string; items: Option[] }
  const groups: Group[] = useMemo(() => {
    const acc: Group[] = []
    for (const [groupName, zones] of Object.entries(grouped)) {
      const items = zones.map((z) => {
        const offset = formatOffset(z, now)
        const local = formatLocalTime(z, now)
        const label = local ? `${z} — ${local}${offset ? ` (${offset})` : ''}` : z
        return { label, value: z, offset }
      })
      acc.push({ groupLabel: groupName, items })
    }
    return acc
  }, [grouped, now])

  // Flattened list for filter-by-value/offset lookups.
  const flatOptions: Option[] = useMemo(() => groups.flatMap((g) => g.items), [groups])

  const stringValue = value === null || value === undefined ? '' : String(value)

  const handleAutoDetect = () => {
    const detected = detectBrowserTimezone()
    if (detected && flatOptions.some((o) => o.value === detected)) {
      onChange(detected)
    } else if (detected) {
      // Detected zone isn't in the PHP list — fall back to the same value and
      // let server validation handle it.
      onChange(detected)
    }
  }

  // ⭐ D3 — custom group header. The built-in PrimeReact `.p-dropdown-item-group`
  // renders with a theme-default background that shows as a harsh black bar
  // inside the Martis panel. Render our own styled header tied to CSS vars so
  // the divider line reads as a label, not a bug.
  const optionGroupTemplate = (g: { groupLabel: string }) => (
    <div
      className="flex items-center text-[11px] font-semibold uppercase tracking-wide py-1.5 px-3"
      style={{
        color: 'var(--martis-text-muted)',
        backgroundColor: 'var(--martis-surface-alt)',
        letterSpacing: '0.04em',
      }}
    >
      {g.groupLabel}
    </div>
  )

  const clearTip = t('clear', { defaultValue: 'Clear' })

  return (
    <div className="flex flex-col gap-1">
      <div className="flex items-stretch gap-1.5">
        <Dropdown
          inputId={field.attribute}
          name={field.attribute}
          value={stringValue}
          options={groups}
          optionGroupLabel="groupLabel"
          optionGroupChildren="items"
          optionGroupTemplate={optionGroupTemplate}
          onChange={(e) => onChange(e.value as string)}
          disabled={field.readonly}
          invalid={!!error}
          placeholder={field.placeholder ?? t('timezone_select_placeholder')}
          showClear={field.nullable || !field.required}
          filter
          filterBy="label,value,offset"
          filterPlaceholder={t('timezone_filter_placeholder')}
          emptyFilterMessage={t('no_results_found')}
          className="flex-1 min-w-0"
          data-testid={`timezone-input-${field.attribute}`}
          pt={{
            clearIcon: dropdownClearIconPt(clearTip),
            // The default PrimeReact item-group `<li>` renders with a dark
            // background inherited from the theme. Zero-out the parent so the
            // styled template below is the only thing you see.
            itemGroup: {
              style: {
                backgroundColor: 'transparent',
                padding: 0,
                margin: 0,
                color: 'inherit',
              },
            } as Record<string, unknown>,
          }}
        />
        {!field.readonly && (
          <button
            type="button"
            onClick={handleAutoDetect}
            className="flex-shrink-0 inline-flex items-center justify-center rounded-md border transition-colors hover:opacity-90 focus:outline-none"
            style={{
              width: '2.25rem',  // matches the standardized input height
              height: '2.25rem',
              borderColor: 'var(--martis-border)',
              backgroundColor: 'var(--martis-surface)',
              color: 'var(--martis-text-muted)',
            }}
            data-pr-tooltip={t('timezone_auto_detect')}
            data-pr-position="top"
            data-testid={`timezone-autodetect-${field.attribute}`}
            aria-label={t('timezone_auto_detect')}
          >
            <CrosshairIcon size={16} />
          </button>
        )}
      </div>
      {error && <small className="text-red-500">{error}</small>}
    </div>
  )
}
