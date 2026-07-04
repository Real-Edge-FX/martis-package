import { useMemo, useRef, useState, useEffect } from 'react'
import { useTranslation } from 'react-i18next'
import { MagnifyingGlassIcon, XIcon, CaretDownIcon, SmileyBlankIcon } from '@phosphor-icons/react'
import type { FieldDisplayProps, FieldInputProps } from './types'
import { ResourceIcon } from '@/components/ResourceIcon'

// -----------------------------------------------------------------------------
// Semantic color tokens — map friendly names to Martis CSS vars.
// -----------------------------------------------------------------------------

const SEMANTIC_TOKENS: Record<string, string> = {
  success: 'var(--martis-success)',
  warning: 'var(--martis-warning)',
  danger: 'var(--martis-danger)',
  info: 'var(--martis-info)',
  muted: 'var(--martis-text-muted)',
  accent: 'var(--martis-accent)',
  primary: 'var(--martis-accent)',
}

/** Turn a semantic token / CSS var / arbitrary CSS color into a usable CSS string. */
function resolveIconColor(input: string | null | undefined): string | undefined {
  if (!input) return undefined
  const trimmed = input.trim()
  if (trimmed === '') return undefined
  const key = trimmed.toLowerCase()
  if (SEMANTIC_TOKENS[key]) return SEMANTIC_TOKENS[key]
  // `var(--…)`, `#hex`, `rgb(…)`, named color → use as-is.
  return trimmed
}

// -----------------------------------------------------------------------------
// Curated palette used when no explicit palette is configured on the field.
// Keeps the picker sane without pulling in every Phosphor icon (~1500+).
// -----------------------------------------------------------------------------

const DEFAULT_PALETTE: string[] = [
  'rocket', 'star', 'heart', 'fire', 'crown', 'lightning', 'sparkle', 'trophy',
  'target', 'flag', 'check', 'x', 'warning', 'info', 'question', 'bell',
  'book', 'briefcase', 'buildings', 'calendar', 'camera', 'car', 'chart-bar',
  'chat', 'cloud', 'code', 'compass', 'cube', 'database', 'envelope', 'eye',
  'gear', 'gift', 'globe', 'graduation-cap', 'hand-coins', 'house', 'image',
  'key', 'laptop', 'lightbulb', 'link', 'lock', 'magnifying-glass', 'map-pin',
  'medal', 'megaphone', 'microphone', 'moon', 'mountains', 'music-note',
  'package', 'palette', 'paw-print', 'pen', 'phone', 'plug', 'printer',
  'rocket-launch', 'scales', 'scissors', 'shield', 'shopping-bag',
  'shopping-cart', 'smiley', 'snowflake', 'sun', 'tag', 'telephone', 'tent',
  'thumbs-up', 'timer', 'tree', 'umbrella', 'user', 'users', 'video-camera',
  'wallet', 'watch', 'wrench',
]

// -----------------------------------------------------------------------------
// Resolve the icon+color pair from either a server-resolved shape, a raw
// string (stored field without mapping), or nothing.
// -----------------------------------------------------------------------------

interface IconExtras {
  stored?: boolean
  fixedIcon?: string | null
  color?: string | null
  colorFrom?: string | null
  map?: Record<string, { icon: string; color?: string | null }>
  palette?: string[]
  size?: number
}

type IconPair = { icon: string | null; color: string | null }

function coerceToPair(value: unknown, extras: IconExtras): IconPair {
  // Server returns `{ icon, color }` for resolved icons.
  if (value && typeof value === 'object' && 'icon' in value) {
    const v = value as { icon?: unknown; color?: unknown }
    return {
      icon: typeof v.icon === 'string' ? v.icon : null,
      color: typeof v.color === 'string' ? v.color : null,
    }
  }

  // Raw string (stored field, resolved via base toArray without model).
  if (typeof value === 'string' && value !== '') {
    const mapped = extras.map?.[value]
    if (mapped) {
      return { icon: mapped.icon, color: mapped.color ?? extras.color ?? null }
    }
    return { icon: value, color: extras.color ?? null }
  }

  // Fall back to the fixed icon configured on the field.
  if (extras.fixedIcon) {
    return { icon: extras.fixedIcon, color: extras.color ?? null }
  }

  return { icon: null, color: null }
}

// -----------------------------------------------------------------------------
// Display — used on index + detail. Simply renders the resolved icon.
// -----------------------------------------------------------------------------

export function IconFieldDisplay({ field, value }: FieldDisplayProps) {
  const extras = field as unknown as IconExtras
  const pair = coerceToPair(value, extras)

  if (!pair.icon) {
    return <span className="text-gray-400 dark:text-gray-500">—</span>
  }

  const size = extras.size ?? 16
  const color = resolveIconColor(pair.color) ?? 'var(--martis-text)'

  return (
    <span className="inline-flex items-center" style={{ color }} data-testid={`icon-display-${field.attribute}`}>
      <ResourceIcon iconName={pair.icon} size={size} />
    </span>
  )
}

// -----------------------------------------------------------------------------
// Input — the ⭐ picker. Only rendered for stored fields; display-only and
// computed modes are form-hidden by default so the input never shows.
// -----------------------------------------------------------------------------

export function IconFieldInput({ field, value, onChange, error }: FieldInputProps) {
  const { t } = useTranslation('messages')
  const extras = field as unknown as IconExtras

  // Hooks must run unconditionally, before any early return (rules-of-hooks).
  const palette = extras.palette && extras.palette.length > 0 ? extras.palette : DEFAULT_PALETTE
  const [open, setOpen] = useState(false)
  const [query, setQuery] = useState('')
  const ref = useRef<HTMLDivElement | null>(null)

  // Close the popover when clicking outside.
  useEffect(() => {
    if (!open) return
    const handle = (e: MouseEvent) => {
      if (ref.current && !ref.current.contains(e.target as Node)) setOpen(false)
    }
    document.addEventListener('mousedown', handle)
    return () => document.removeEventListener('mousedown', handle)
  }, [open])

  const filtered = useMemo(() => {
    if (query === '') return palette
    const needle = query.toLowerCase()
    return palette.filter((name) => name.toLowerCase().includes(needle))
  }, [palette, query])

  if (!extras.stored) {
    // Safety net — a display-only Icon field should never reach a form
    // renderer, but if a resource mistakenly puts it there we render a
    // disabled read-out instead of a broken picker.
    const pair = coerceToPair(value, extras)
    return (
      <span className="inline-flex items-center gap-2 text-xs" style={{ color: 'var(--martis-text-muted)' }}>
        {pair.icon ? <ResourceIcon iconName={pair.icon} size={extras.size ?? 16} /> : '—'}
        <span>{t('icon_display_only', 'Display only')}</span>
      </span>
    )
  }

  const size = extras.size ?? 16
  const selected = typeof value === 'string' && value !== '' ? value : null
  const selectedColor = resolveIconColor(extras.color) ?? 'var(--martis-text)'

  return (
    <div ref={ref} className="relative flex flex-col gap-1">
      <button
        type="button"
        onClick={() => !field.readonly && setOpen((v) => !v)}
        disabled={field.readonly}
        className="flex items-center justify-between gap-2 rounded-md border transition-colors focus:outline-none focus-visible:ring-2"
        style={{
          height: 'var(--martis-input-height, 2.25rem)',
          paddingLeft: '0.625rem',
          paddingRight: '0.5rem',
          borderColor: error ? 'var(--martis-danger)' : open ? 'var(--martis-accent)' : 'var(--martis-border)',
          backgroundColor: 'var(--martis-surface)',
          color: 'var(--martis-text)',
          opacity: field.readonly ? 0.7 : 1,
          cursor: field.readonly ? 'not-allowed' : 'pointer',
        }}
        data-testid={`icon-input-${field.attribute}`}
        aria-haspopup="listbox"
        aria-expanded={open}
      >
        {/* Left: selected icon + label, or placeholder icon + hint. */}
        <span className="inline-flex items-center gap-2 min-w-0 flex-1">
          {selected ? (
            <>
              <span
                className="inline-flex flex-shrink-0 items-center justify-center rounded-md"
                style={{
                  width: '1.5rem',
                  height: '1.5rem',
                  color: selectedColor,
                  backgroundColor: 'color-mix(in oklab, currentColor 12%, transparent)',
                }}
              >
                <ResourceIcon iconName={selected} size={size} />
              </span>
              <span className="text-sm truncate" style={{ color: 'var(--martis-text)' }}>
                {selected}
              </span>
            </>
          ) : (
            <>
              <span
                className="inline-flex flex-shrink-0 items-center justify-center"
                style={{ width: '1.5rem', height: '1.5rem', color: 'var(--martis-text-muted)' }}
              >
                <SmileyBlankIcon size={18} />
              </span>
              <span className="text-sm truncate" style={{ color: 'var(--martis-text-muted)' }}>
                {field.placeholder ?? t('icon_picker_select', 'Select an icon…')}
              </span>
            </>
          )}
        </span>

        {/* Right: optional clear (only when nullable + selected) + caret. */}
        <span className="inline-flex flex-shrink-0 items-center gap-1">
          {selected && field.nullable && !field.readonly && (
            <span
              role="button"
              tabIndex={-1}
              onMouseDown={(e) => e.stopPropagation()}
              onClick={(e) => {
                e.stopPropagation()
                onChange(null)
              }}
              className="flex items-center justify-center rounded hover:opacity-80"
              style={{ width: '1.25rem', height: '1.25rem', color: 'var(--martis-danger)' }}
              aria-label={t('clear', 'Clear')}
              data-pr-tooltip={t('clear', 'Clear')}
              data-pr-position="top"
            >
              <XIcon size={12} weight="bold" />
            </span>
          )}
          <CaretDownIcon
            size={12}
            weight="bold"
            style={{
              color: 'var(--martis-text-muted)',
              transition: 'transform 150ms ease',
              transform: open ? 'rotate(180deg)' : 'rotate(0)',
            }}
          />
        </span>
      </button>

      {open && (
        <div
          className="absolute z-50 top-full left-0 right-0 mt-1 min-w-[260px] rounded-md border shadow-lg"
          style={{
            backgroundColor: 'var(--martis-card)',
            borderColor: 'var(--martis-border)',
          }}
          data-testid={`icon-picker-${field.attribute}`}
        >
          <div className="flex items-center gap-2 border-b px-3 py-2" style={{ borderColor: 'var(--martis-border)' }}>
            <MagnifyingGlassIcon size={14} style={{ color: 'var(--martis-text-muted)' }} />
            <input
              autoFocus
              type="text"
              value={query}
              onChange={(e) => setQuery(e.target.value)}
              placeholder={t('icon_picker_search', 'Search icons…')}
              className="flex-1 bg-transparent text-sm focus:outline-none"
              style={{ color: 'var(--martis-text)' }}
            />
          </div>
          <div className="max-h-[220px] overflow-y-auto p-2">
            {filtered.length === 0 ? (
              <div className="px-3 py-4 text-center text-xs" style={{ color: 'var(--martis-text-muted)' }}>
                {t('icon_picker_no_results', 'No icons found.')}
              </div>
            ) : (
              <div className="grid grid-cols-6 gap-1">
                {filtered.map((name) => {
                  const isActive = name === selected
                  return (
                    <button
                      key={name}
                      type="button"
                      onClick={() => {
                        onChange(name)
                        setOpen(false)
                        setQuery('')
                      }}
                      className="flex aspect-square items-center justify-center rounded-md transition-colors focus:outline-none"
                      style={{
                        backgroundColor: isActive ? 'var(--martis-accent-bg-light)' : 'transparent',
                        color: isActive ? 'var(--martis-accent)' : 'var(--martis-text)',
                      }}
                      onMouseEnter={(e) => {
                        if (!isActive) e.currentTarget.style.backgroundColor = 'var(--martis-hover)'
                      }}
                      onMouseLeave={(e) => {
                        e.currentTarget.style.backgroundColor = isActive ? 'var(--martis-accent-bg-light)' : 'transparent'
                      }}
                      data-pr-tooltip={name}
                      data-pr-position="top"
                      data-icon-name={name}
                    >
                      <ResourceIcon iconName={name} size={20} />
                    </button>
                  )
                })}
              </div>
            )}
          </div>
        </div>
      )}

      {error && <small className="text-red-500">{error}</small>}
    </div>
  )
}
