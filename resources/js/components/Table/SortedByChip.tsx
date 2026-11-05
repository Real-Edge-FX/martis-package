import { useEffect, useRef, useState } from 'react'
import { createPortal } from 'react-dom'
import { useTranslation } from 'react-i18next'
import { ArrowDownIcon, ArrowUpIcon, CaretDownIcon, CaretUpIcon, PlusIcon, XIcon } from '@phosphor-icons/react'
import { useEscapeLayer } from '@/lib/escapeLayers'
import { MAX_SORT_KEYS, moveSortKey, sameSort, type SortKey } from '@/lib/multiSort'

export interface SortableColumn {
  attribute: string
  label: string
}

export interface SortedByChipProps {
  sort: readonly SortKey[]
  /** The sort the list has when the user picked none (the resource's `defaultSort()`). */
  defaultSort: readonly SortKey[]
  /** The columns a user may sort by. */
  columns: readonly SortableColumn[]
  onChange: (keys: SortKey[]) => void
}

/**
 * "Sorted by Language ↑, Category ↑": the toolbar chip of a list sorted
 * other than by its default. It opens a popover to reorder the keys (move
 * up / down buttons, so a keyboard and a touch screen reach it as well as
 * a mouse), flip a direction, remove a key and add a column. It is the way
 * to sort by several columns where there is no Shift key.
 */
export function SortedByChip({ sort, defaultSort, columns, onChange }: SortedByChipProps) {
  const { t } = useTranslation('resources')
  const [open, setOpen] = useState(false)
  const [adding, setAdding] = useState(false)
  const buttonRef = useRef<HTMLButtonElement>(null)
  const popoverRef = useRef<HTMLDivElement>(null)

  useEscapeLayer(open, () => { setOpen(false); buttonRef.current?.focus() })

  useEffect(() => {
    if (!open) return
    const onPointerDown = (e: MouseEvent) => {
      const target = e.target as Node
      if (popoverRef.current?.contains(target) || buttonRef.current?.contains(target)) return
      setOpen(false)
    }
    document.addEventListener('mousedown', onPointerDown)
    return () => document.removeEventListener('mousedown', onPointerDown)
  }, [open])

  useEffect(() => {
    if (!open) setAdding(false)
  }, [open])

  if (sameSort(sort, defaultSort)) return null

  const labelOf = (attribute: string) => columns.find((c) => c.attribute === attribute)?.label ?? attribute
  const arrow = (key: SortKey) => (key.direction === 'asc' ? '↑' : '↓')
  const summary = sort.length === 0
    ? t('sort_default', { defaultValue: 'default order' })
    : sort.map((key) => `${labelOf(key.attribute)} ${arrow(key)}`).join(', ')
  const remaining = columns.filter((c) => !sort.some((key) => key.attribute === c.attribute))
  const rect = open ? buttonRef.current?.getBoundingClientRect() : undefined

  const directionLabel = (key: SortKey) => key.direction === 'asc'
    ? t('sort_ascending', { defaultValue: 'ascending' })
    : t('sort_descending', { defaultValue: 'descending' })

  return (
    <>
      <button
        ref={buttonRef}
        type="button"
        className="martis-sort-chip"
        aria-haspopup="dialog"
        aria-expanded={open}
        onClick={() => setOpen((v) => !v)}
      >
        <span className="martis-text-muted">{t('sorted_by', { defaultValue: 'Sorted by' })}</span>
        <span className="truncate">{summary}</span>
        <CaretDownIcon size={11} weight="bold" />
      </button>
      {open && rect && createPortal(
        <div
          ref={popoverRef}
          role="dialog"
          aria-label={t('sort_columns', { defaultValue: 'Sort columns' })}
          className="martis-sort-popover"
          style={{ top: rect.bottom + 4, left: Math.max(8, Math.min(rect.left, window.innerWidth - 280)) }}
        >
          {sort.map((key, index) => (
            <div key={key.attribute} className="martis-sort-popover__row" data-sort-key={key.attribute}>
              <span className="martis-th-sort-order" aria-hidden="true">{index + 1}</span>
              <span className="flex-1 truncate">{labelOf(key.attribute)}</span>
              <button
                type="button"
                className="martis-sort-popover__btn"
                aria-label={`${labelOf(key.attribute)}: ${t('sort_move_up', { defaultValue: 'Move up' })}`}
                disabled={index === 0}
                onClick={() => onChange(moveSortKey(sort, index, -1))}
              >
                <ArrowUpIcon size={14} />
              </button>
              <button
                type="button"
                className="martis-sort-popover__btn"
                aria-label={`${labelOf(key.attribute)}: ${t('sort_move_down', { defaultValue: 'Move down' })}`}
                disabled={index === sort.length - 1}
                onClick={() => onChange(moveSortKey(sort, index, 1))}
              >
                <ArrowDownIcon size={14} />
              </button>
              <button
                type="button"
                className="martis-sort-popover__btn"
                aria-label={`${labelOf(key.attribute)}: ${t('sort_toggle_direction', { defaultValue: 'Change direction' })} (${directionLabel(key)})`}
                onClick={() => onChange(sort.map((k, i) => (i === index ? { ...k, direction: k.direction === 'asc' ? 'desc' : 'asc' } : k)))}
              >
                {key.direction === 'asc' ? <CaretUpIcon size={14} weight="bold" /> : <CaretDownIcon size={14} weight="bold" />}
              </button>
              <button
                type="button"
                className="martis-sort-popover__btn"
                aria-label={`${labelOf(key.attribute)}: ${t('sort_remove', { defaultValue: 'Remove' })}`}
                onClick={() => onChange(sort.filter((_, i) => i !== index))}
              >
                <XIcon size={14} weight="bold" />
              </button>
            </div>
          ))}

          {sort.length < MAX_SORT_KEYS && remaining.length > 0 && (
            adding ? (
              <select
                className="martis-perpage-select mt-1 w-full"
                aria-label={t('sort_add_column', { defaultValue: 'Add column' })}
                defaultValue=""
                autoFocus
                onChange={(e) => {
                  if (!e.target.value) return
                  onChange([...sort, { attribute: e.target.value, direction: 'asc' }])
                  setAdding(false)
                }}
              >
                <option value="" disabled>{t('sort_add_column', { defaultValue: 'Add column' })}</option>
                {remaining.map((c) => <option key={c.attribute} value={c.attribute}>{c.label}</option>)}
              </select>
            ) : (
              <button
                type="button"
                className="martis-btn-ghost martis-btn-sm mt-1 inline-flex items-center gap-1.5"
                onClick={() => setAdding(true)}
              >
                <PlusIcon size={13} weight="bold" />
                {t('sort_add_column', { defaultValue: 'Add column' })}
              </button>
            )
          )}
        </div>,
        document.body,
      )}
    </>
  )
}
