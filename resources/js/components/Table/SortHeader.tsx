import { useLayoutEffect, useRef } from 'react'
import { useTranslation } from 'react-i18next'
import { CaretDownIcon, CaretUpDownIcon, CaretUpIcon } from '@phosphor-icons/react'
import type { SortKey } from '@/lib/multiSort'

export interface SortHeaderProps {
  attribute: string
  label: string
  /** The keys the list is sorted by, the most significant first. */
  sort: readonly SortKey[]
  /** `additive` is true for Shift+click and Shift+Enter. */
  onSort: (attribute: string, additive: boolean) => void
  /** Column alignment (`Field::textAlign()`); the sort icon stays beside the label. */
  align?: 'left' | 'center' | 'right'
}

const ALIGN_JUSTIFY = { left: 'flex-start', center: 'center', right: 'flex-end' } as const

/**
 * The header button of a sortable column, shared by the index, the lens and
 * the relationship panels.
 *
 * Click sorts by the column alone, Shift+click (Shift+Enter on the focused
 * button) adds it to the sort. With more than one key each sorted header
 * shows its position. `aria-sort` goes on the first key's header only (WAI-ARIA
 * asks for one sorted header at a time); the others say their place in the
 * order in visually hidden text.
 */
export function SortHeader({ attribute, label, sort, onSort, align }: SortHeaderProps) {
  const { t } = useTranslation('resources')
  const buttonRef = useRef<HTMLButtonElement>(null)

  const index = sort.findIndex((key) => key.attribute === attribute)
  const key = index === -1 ? null : sort[index]
  const multi = sort.length > 1
  const directionWord = key?.direction === 'desc'
    ? t('sort_descending', { defaultValue: 'descending' })
    : t('sort_ascending', { defaultValue: 'ascending' })

  // The `th` belongs to the table (PrimeReact draws it): reflect the first
  // key's direction on it, as a native sortable header would.
  useLayoutEffect(() => {
    const th = buttonRef.current?.closest('th')
    if (!th) return
    if (index === 0 && key) th.setAttribute('aria-sort', key.direction === 'asc' ? 'ascending' : 'descending')
    else th.removeAttribute('aria-sort')
  })

  return (
    <button
      ref={buttonRef}
      type="button"
      className="martis-th-sortable flex items-center gap-1 font-bold uppercase tracking-wider text-xs"
      style={{
        color: 'var(--martis-table-header-text)',
        ...(align ? { width: '100%', justifyContent: ALIGN_JUSTIFY[align] } : {}),
      }}
      onClick={(e) => onSort(attribute, e.shiftKey)}
      onKeyDown={(e) => {
        // Enter on a button clicks it without the Shift state in every
        // browser; take Shift+Enter here and stop the click that follows.
        if (e.key === 'Enter' && e.shiftKey) {
          e.preventDefault()
          onSort(attribute, true)
        }
      }}
    >
      {label}
      {key ? (
        key.direction === 'asc'
          ? <CaretUpIcon size={12} weight="bold" style={{ opacity: 0.8 }} />
          : <CaretDownIcon size={12} weight="bold" style={{ opacity: 0.8 }} />
      ) : (
        // Discreet hint that the column is sortable. Low opacity at rest,
        // slightly brighter when the header is hovered.
        <CaretUpDownIcon size={11} weight="bold" className="martis-th-sort-hint" />
      )}
      {key && multi && (
        <span className="martis-th-sort-order" aria-hidden="true">{index + 1}</span>
      )}
      {key && multi && index > 0 && (
        <span className="sr-only">
          {t('sort_position', {
            defaultValue: 'sort {{position}} of {{total}}, {{direction}}',
            position: index + 1,
            total: sort.length,
            direction: directionWord,
          })}
        </span>
      )}
    </button>
  )
}
