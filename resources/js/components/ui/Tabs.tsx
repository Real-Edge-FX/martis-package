import type { ReactNode } from 'react'

interface TabItem<T extends string = string> {
  /** Stable identifier — also the value yielded by `onChange`. */
  key: T
  /** Visible label. */
  label: ReactNode
  /** Optional leading icon rendered before the label. */
  icon?: ReactNode
  /** Optional numeric count pill on the right. */
  count?: number
  /** Disable the tab (visual + keyboard). */
  disabled?: boolean
}

interface TabsProps<T extends string = string> {
  /** Tabs to render. First match on `active` is selected. */
  items: Array<TabItem<T>>
  /** Currently active tab key. */
  active: T
  /** Called with the new tab key when the user switches. */
  onChange: (key: T) => void
  /** Optional wrapper className. */
  className?: string
  /** ARIA label for the tab list (leave unset when the surrounding heading is enough). */
  ariaLabel?: string
}

/**
 * Horizontal tab strip matching Catalog `.tabs` spec — muted text at rest,
 * hover flips to default text, 2px accent underline on the active tab, and
 * an optional count pill that picks up accent tinting when active.
 */
export function Tabs<T extends string = string>({
  items,
  active,
  onChange,
  className,
  ariaLabel,
}: TabsProps<T>) {
  const wrapperClass = className ? `martis-tabs ${className}` : 'martis-tabs'
  return (
    <div className={wrapperClass} role="tablist" aria-label={ariaLabel}>
      {items.map((item) => {
        const isActive = item.key === active
        return (
          <button
            key={item.key}
            type="button"
            role="tab"
            aria-selected={isActive}
            disabled={item.disabled}
            className={isActive ? 'martis-tabs-tab is-active' : 'martis-tabs-tab'}
            onClick={() => {
              if (!item.disabled && !isActive) onChange(item.key)
            }}
          >
            {item.icon}
            {item.label}
            {typeof item.count === 'number' && (
              <span className="martis-tabs-count">{item.count}</span>
            )}
          </button>
        )
      })}
    </div>
  )
}
