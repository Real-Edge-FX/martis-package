import { useEffect } from 'react'
import { createPortal } from 'react-dom'
import { useTranslation } from 'react-i18next'
import { WarningIcon } from '@phosphor-icons/react'
import { ResourceIcon } from '@/components/ResourceIcon'
import type { UnsavedChangesConfig } from '@/types'

interface Props {
  open: boolean
  onConfirm: () => void
  onCancel: () => void
  config?: UnsavedChangesConfig | null
}

/** Map semantic color tokens to CSS vars (same set the Icon field uses). */
const SEMANTIC_COLORS: Record<string, string> = {
  success: 'var(--martis-success)',
  warning: 'var(--martis-warning)',
  danger: 'var(--martis-danger)',
  info: 'var(--martis-info)',
  muted: 'var(--martis-text-muted)',
  accent: 'var(--martis-accent)',
  primary: 'var(--martis-accent)',
}

function resolveColor(input?: string | null, fallback?: string): string | undefined {
  if (!input) return fallback
  const key = input.trim().toLowerCase()
  if (SEMANTIC_COLORS[key]) return SEMANTIC_COLORS[key]
  return input.trim() || fallback
}

/**
 * Lightweight confirmation modal shown when a form drawer tries to close
 * while `isDirty === true`. Rendered via a portal to `document.body` so
 * it layers above the drawer (which itself is already portal'd).
 *
 * The `config` prop comes from the resource's `confirmUnsavedChanges()`
 * PHP method — when a full UnsavedChangesConfig is returned, it overrides
 * the localised defaults for title / body / icon / colours / labels.
 */
export function UnsavedChangesDialog({ open, onConfirm, onCancel, config }: Props) {
  const { t } = useTranslation('messages')

  // Escape cancels (i.e. keeps editing) to avoid "accidentally discard".
  useEffect(() => {
    if (!open) return
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') {
        e.stopImmediatePropagation()
        e.preventDefault()
        onCancel()
      }
    }
    document.addEventListener('keydown', onKey, true)
    return () => document.removeEventListener('keydown', onKey, true)
  }, [open, onCancel])

  if (!open) return null

  const title = config?.title ?? t('unsaved_changes_title', { defaultValue: 'Unsaved changes' })
  const body = config?.body ?? t('unsaved_changes_body', { defaultValue: 'You have unsaved changes. Discard them and close?' })
  const confirmLabel = config?.confirmLabel ?? t('discard', { defaultValue: 'Discard' })
  const cancelLabel = config?.cancelLabel ?? t('keep_editing', { defaultValue: 'Keep editing' })
  const iconName = config?.icon === null ? null : (config?.icon ?? null)  // explicit null hides icon
  const iconColor = resolveColor(config?.iconColor, 'var(--martis-warning)')
  const confirmColor = resolveColor(config?.confirmColor, 'var(--martis-danger)')

  return createPortal(
    <div
      className="fixed inset-0 flex items-center justify-center"
      style={{ zIndex: 10000, backgroundColor: 'rgba(0,0,0,0.55)' }}
      onClick={onCancel}
      data-testid="unsaved-changes-dialog"
    >
      <div
        className="rounded-lg p-5 shadow-2xl"
        style={{
          backgroundColor: 'var(--martis-card)',
          border: '1px solid var(--martis-border)',
          color: 'var(--martis-text)',
          width: '400px',
          maxWidth: '92vw',
        }}
        onClick={(e) => e.stopPropagation()}
      >
        <div className="flex items-start gap-3">
          {/* Hide the icon when the resource explicitly returned `null`
              via UnsavedChangesConfig::icon(null). Otherwise render the
              requested Phosphor glyph, or the default warning triangle. */}
          {iconName !== '' && (
            <span
              className="flex flex-shrink-0 items-center justify-center rounded-full"
              style={{
                width: '2.25rem',
                height: '2.25rem',
                backgroundColor: `color-mix(in oklab, ${iconColor ?? 'var(--martis-warning)'} 16%, transparent)`,
                color: iconColor,
              }}
            >
              {iconName ? (
                <ResourceIcon iconName={iconName} size={20} />
              ) : (
                <WarningIcon size={20} weight="fill" />
              )}
            </span>
          )}
          <div className="min-w-0 flex-1">
            <h2 className="text-base font-semibold" style={{ color: 'var(--martis-text)' }}>
              {title}
            </h2>
            <p className="mt-1 text-sm" style={{ color: 'var(--martis-text-muted)' }}>
              {body}
            </p>
          </div>
        </div>

        <div className="mt-5 flex items-center justify-end gap-2">
          <button
            type="button"
            onClick={onCancel}
            className="martis-btn-secondary"
            data-testid="unsaved-keep-editing"
          >
            {cancelLabel}
          </button>
          <button
            type="button"
            onClick={onConfirm}
            className="martis-btn-filled"
            style={{ backgroundColor: confirmColor }}
            data-testid="unsaved-discard"
          >
            {confirmLabel}
          </button>
        </div>
      </div>
    </div>,
    document.body,
  )
}
