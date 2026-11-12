import { Dropdown } from 'primereact/dropdown'
import { useTranslation } from 'react-i18next'

export interface CompactSelectOption<V extends string | number> {
  label: string
  value: V
}

export interface CompactSelectProps<V extends string | number> {
  value: V
  options: ReadonlyArray<CompactSelectOption<V>>
  onChange: (value: V) => void
  /** Accessible name of the control (it has no visible label of its own). */
  ariaLabel: string
  /** Optional hover text, drawn by the global PrimeReact Tooltip. */
  tooltip?: string
}

/**
 * The compact select of the list toolbars (per page, trashed filter).
 *
 * A PrimeReact `Dropdown` so the control and its option panel follow the
 * theme (dark mode included) like the filter selects do, where a native
 * `<select>` opens an operating-system menu. The root keeps the
 * `martis-perpage-select` class, sized like the native control it replaced.
 */
export function CompactSelect<V extends string | number>({ value, options, onChange, ariaLabel, tooltip }: CompactSelectProps<V>) {
  const select = (
    <Dropdown
      value={value}
      options={options as CompactSelectOption<V>[]}
      optionLabel="label"
      optionValue="value"
      onChange={(e) => onChange(e.value as V)}
      ariaLabel={ariaLabel}
      // PrimeReact puts `ariaLabel` on the non-focusable trigger only; the
      // focused element is its hidden readonly input, which takes aria-*.
      aria-label={ariaLabel}
      className="martis-perpage-select"
      appendTo={typeof document !== 'undefined' ? document.body : undefined}
    />
  )
  if (!tooltip) return select
  return (
    <span className="inline-flex" data-pr-tooltip={tooltip} data-pr-position="top">
      {select}
    </span>
  )
}

export interface PerPageSelectProps {
  value: number
  options: ReadonlyArray<number>
  onChange: (value: number) => void
}

/** The "rows per page" select, with its "Per page:" label. */
export function PerPageSelect({ value, options, onChange }: PerPageSelectProps) {
  const { t } = useTranslation('resources')
  const label = t('per_page', 'Per page')
  return (
    <div className="flex items-center gap-2 flex-shrink-0">
      <label className="text-xs martis-text-muted whitespace-nowrap">{label}:</label>
      <CompactSelect
        value={value}
        options={options.map((opt) => ({ label: String(opt), value: opt }))}
        onChange={onChange}
        ariaLabel={label}
      />
    </div>
  )
}

export type TrashedValue = '' | 'with' | 'only'

export interface TrashedSelectProps {
  value: TrashedValue
  onChange: (value: TrashedValue) => void
  /** Option labels; the callers supply their translated copy. */
  labels: { active: string; with: string; only: string }
  ariaLabel: string
  tooltip?: string
}

/** The soft-delete filter select: active only, with trashed, only trashed. */
export function TrashedSelect({ value, onChange, labels, ariaLabel, tooltip }: TrashedSelectProps) {
  return (
    <div className="flex items-center gap-2 flex-shrink-0">
      <CompactSelect<TrashedValue>
        value={value}
        options={[
          { label: labels.active, value: '' },
          { label: labels.with, value: 'with' },
          { label: labels.only, value: 'only' },
        ]}
        onChange={onChange}
        ariaLabel={ariaLabel}
      // PrimeReact puts `ariaLabel` on the non-focusable trigger only; the
      // focused element is its hidden readonly input, which takes aria-*.
      aria-label={ariaLabel}
        tooltip={tooltip}
      />
    </div>
  )
}
