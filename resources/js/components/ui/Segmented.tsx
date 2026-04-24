import type { ReactNode } from 'react'

interface SegmentedOption<T extends string = string> {
  /** Stable identifier — also the value yielded by `onChange`. */
  key: T
  /** Visible label. */
  label: ReactNode
  /** Optional leading icon rendered before the label. */
  icon?: ReactNode
  /** Disable the option (visual + keyboard). */
  disabled?: boolean
  /** Optional `aria-label` for icon-only options. */
  ariaLabel?: string
}

interface SegmentedProps<T extends string = string> {
  /** Options to render — each becomes an equal-width segment. */
  options: Array<SegmentedOption<T>>
  /** Currently selected key. */
  value: T
  /** Called with the new key when the user picks a different segment. */
  onChange: (key: T) => void
  /** Wrapper className. */
  className?: string
  /** Stretch the container to the full width of its parent. */
  fullWidth?: boolean
  /** ARIA label for the group when the surrounding context isn't enough. */
  ariaLabel?: string
}

/**
 * Segmented control matching Catalog `.seg` spec — input-bg track, equal-width
 * 26px buttons, muted text at rest, active segment lifts with a `surface`
 * background + `shadow-sm` so it reads as the "selected" choice. Used for
 * small view switchers (theme, density, per-page).
 */
export function Segmented<T extends string = string>({
  options,
  value,
  onChange,
  className,
  fullWidth,
  ariaLabel,
}: SegmentedProps<T>) {
  const baseClass = fullWidth ? 'martis-segmented is-full' : 'martis-segmented'
  const wrapperClass = className ? `${baseClass} ${className}` : baseClass
  return (
    <div className={wrapperClass} role="group" aria-label={ariaLabel}>
      {options.map((option) => {
        const isActive = option.key === value
        return (
          <button
            key={option.key}
            type="button"
            aria-pressed={isActive}
            aria-label={option.ariaLabel}
            disabled={option.disabled}
            className={isActive ? 'martis-segmented-btn is-active' : 'martis-segmented-btn'}
            onClick={() => {
              if (!option.disabled && !isActive) onChange(option.key)
            }}
          >
            {option.icon}
            {option.label}
          </button>
        )
      })}
    </div>
  )
}
