import { useEffect, useRef, useState } from 'react'
import { CaretDownIcon, EyeIcon } from '@phosphor-icons/react'
import { useTranslation } from 'react-i18next'
import type { LensDefinition } from '@/types'

interface LensDropdownProps {
  lenses: LensDefinition[]
  currentUriKey: string | null
  onSelect: (lens: LensDefinition | null) => void
}

/**
 * Lens selector rendered in the resource-index toolbar.
 * Shows a "Lenses" dropdown that switches the index to an alternative
 * dataset. The "Default View" entry returns the user to the resource's
 * standard index.
 */
export function LensDropdown({ lenses, currentUriKey, onSelect }: LensDropdownProps) {
  const { t } = useTranslation('messages')
  const [open, setOpen] = useState(false)
  const ref = useRef<HTMLDivElement | null>(null)

  useEffect(() => {
    if (!open) return
    const handle = (e: MouseEvent) => {
      if (ref.current && !ref.current.contains(e.target as Node)) {
        setOpen(false)
      }
    }
    document.addEventListener('mousedown', handle)
    return () => document.removeEventListener('mousedown', handle)
  }, [open])

  if (lenses.length === 0) return null

  const active = lenses.find((l) => l.uriKey === currentUriKey) ?? null
  const label = active ? active.name : t('lens_default_view', 'Default View')

  return (
    <div ref={ref} className="relative">
      <button
        type="button"
        onClick={() => setOpen((v) => !v)}
        className="inline-flex items-center gap-2 rounded-md border px-3 py-1.5 text-sm transition-colors"
        style={{
          borderColor: 'var(--martis-border)',
          backgroundColor: active ? 'var(--martis-accent-bg)' : 'var(--martis-surface)',
          color: active ? 'var(--martis-accent)' : 'var(--martis-text)',
          height: 'var(--martis-input-height, 2.25rem)',
        }}
        aria-haspopup="menu"
        aria-expanded={open}
      >
        <EyeIcon size={16} weight={active ? 'fill' : 'regular'} />
        <span className="font-medium">{label}</span>
        <CaretDownIcon size={12} weight="bold" />
      </button>

      {open && (
        <div
          className="absolute z-50 mt-1 min-w-[220px] rounded-md border shadow-lg"
          style={{
            backgroundColor: 'var(--martis-card)',
            borderColor: 'var(--martis-border)',
          }}
          role="menu"
        >
          <button
            type="button"
            onClick={() => {
              setOpen(false)
              onSelect(null)
            }}
            className="flex w-full items-center gap-2 px-3 py-2 text-left text-sm transition-colors"
            style={{
              color: currentUriKey === null ? 'var(--martis-accent)' : 'var(--martis-text)',
              backgroundColor: currentUriKey === null ? 'var(--martis-accent-bg-light)' : 'transparent',
            }}
            onMouseEnter={(e) => {
              if (currentUriKey !== null) e.currentTarget.style.backgroundColor = 'var(--martis-hover)'
            }}
            onMouseLeave={(e) => {
              e.currentTarget.style.backgroundColor = currentUriKey === null ? 'var(--martis-accent-bg-light)' : 'transparent'
            }}
            role="menuitem"
          >
            {t('lens_default_view', 'Default View')}
          </button>
          {lenses.map((lens) => {
            const isActive = lens.uriKey === currentUriKey
            return (
              <button
                key={lens.uriKey}
                type="button"
                onClick={() => {
                  setOpen(false)
                  onSelect(lens)
                }}
                className="flex w-full items-center gap-2 px-3 py-2 text-left text-sm transition-colors"
                style={{
                  color: isActive ? 'var(--martis-accent)' : 'var(--martis-text)',
                  backgroundColor: isActive ? 'var(--martis-accent-bg-light)' : 'transparent',
                }}
                onMouseEnter={(e) => {
                  if (!isActive) e.currentTarget.style.backgroundColor = 'var(--martis-hover)'
                }}
                onMouseLeave={(e) => {
                  e.currentTarget.style.backgroundColor = isActive ? 'var(--martis-accent-bg-light)' : 'transparent'
                }}
                role="menuitem"
              >
                {lens.name}
              </button>
            )
          })}
        </div>
      )}
    </div>
  )
}
