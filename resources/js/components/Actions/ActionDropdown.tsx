import { useState, useRef, useEffect, useCallback } from 'react'
import { createPortal } from 'react-dom'
import { useTranslation } from 'react-i18next'
import { Lightning, CaretDown, Warning } from '@phosphor-icons/react'
import type { ActionMeta } from './ActionModal'

interface ActionDropdownProps {
  actions: ActionMeta[]
  onSelect: (action: ActionMeta) => void
  disabled?: boolean
  label?: string
}

export function ActionDropdown({ actions, onSelect, disabled, label }: ActionDropdownProps) {
  const { t } = useTranslation('actions')
  const [open, setOpen] = useState(false)
  const btnRef = useRef<HTMLButtonElement>(null)
  const menuRef = useRef<HTMLDivElement>(null)
  const [menuPos, setMenuPos] = useState<{ top: number; left: number; width: number }>({ top: 0, left: 0, width: 0 })

  const updatePosition = useCallback(() => {
    if (btnRef.current) {
      const rect = btnRef.current.getBoundingClientRect()
      setMenuPos({
        top: rect.bottom + 4,
        left: rect.left,
        width: Math.max(rect.width, 200),
      })
    }
  }, [])

  useEffect(() => {
    if (!open) return
    updatePosition()
    function handleClick(e: MouseEvent) {
      if (
        menuRef.current && !menuRef.current.contains(e.target as Node) &&
        btnRef.current && !btnRef.current.contains(e.target as Node)
      ) {
        setOpen(false)
      }
    }
    function handleKey(e: KeyboardEvent) {
      if (e.key === 'Escape') setOpen(false)
    }
    document.addEventListener('mousedown', handleClick)
    document.addEventListener('keydown', handleKey)
    return () => {
      document.removeEventListener('mousedown', handleClick)
      document.removeEventListener('keydown', handleKey)
    }
  }, [open, updatePosition])

  if (actions.length === 0) return null

  return (
    <>
      <button
        ref={btnRef}
        type="button"
        onClick={() => setOpen(!open)}
        disabled={disabled}
        className="inline-flex items-center gap-1.5 rounded-lg border px-3 py-1.5 text-sm font-medium transition-colors hover:opacity-90 disabled:opacity-50"
        style={{
          backgroundColor: 'var(--martis-surface)',
          borderColor: 'var(--martis-border)',
          color: 'var(--martis-text)',
        }}
      >
        <Lightning size={14} />
        {label ?? t('actions')}
        <CaretDown size={12} />
      </button>

      {open && createPortal(
        <div
          ref={menuRef}
          className="rounded-lg border shadow-lg"
          style={{
            position: 'fixed',
            top: menuPos.top,
            left: menuPos.left,
            minWidth: menuPos.width,
            zIndex: 9980,
            backgroundColor: 'var(--martis-card)',
            borderColor: 'var(--martis-border)',
          }}
        >
          <div className="py-1">
            {actions.map((action) => (
              <button
                key={action.uriKey}
                type="button"
                onClick={() => {
                  setOpen(false)
                  onSelect(action)
                }}
                className="flex w-full items-center gap-2 px-4 py-2 text-left text-sm transition-colors"
                style={{ color: action.destructive ? '#dc2626' : 'var(--martis-text)' }}
                onMouseEnter={(e) => (e.currentTarget.style.backgroundColor = 'var(--martis-hover)')}
                onMouseLeave={(e) => (e.currentTarget.style.backgroundColor = 'transparent')}
              >
                {action.destructive
                  ? <Warning size={14} weight="fill" />
                  : <Lightning size={14} />
                }
                {action.name}
              </button>
            ))}
          </div>
        </div>,
        document.body,
      )}
    </>
  )
}
