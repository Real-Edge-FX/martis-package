import { useState, useEffect, useCallback, useId, useRef } from 'react'
import { createPortal } from 'react-dom'
import { registry } from '@/lib/registry'
import { useTranslation } from 'react-i18next'
import { WarningIcon, TrashIcon, ArrowCounterClockwiseIcon, XIcon } from '@phosphor-icons/react'
import { useModalHistoryLock } from '@/lib/historyLock'

export interface DeleteModalProps {
  open: boolean
  resourceLabel: string
  isSoftDelete: boolean
  onConfirm: () => Promise<void>
  onCancel: () => void
  confirmMessage?: string
  /** When 'restore', renders confirm UI tuned for restoring a trashed record. */
  variant?: 'delete' | 'restore'
}

function DefaultDeleteModal({
  open,
  resourceLabel,
  isSoftDelete,
  onConfirm,
  onCancel,
  confirmMessage,
  variant = 'delete',
}: DeleteModalProps) {
  const [loading, setLoading] = useState(false)
  const [visible, setVisible] = useState(false)
  const { t: tAct } = useTranslation('actions')
  const { t: tMsg } = useTranslation('messages')

  useEffect(() => {
    if (open) {
      requestAnimationFrame(() => setVisible(true))
    } else {
      setVisible(false)
    }
  }, [open])

  const handleBackdropClose = useCallback(() => {
    setVisible(false)
    setTimeout(onCancel, 200)
  }, [onCancel])

  useEffect(() => {
    if (!open) return
    function handleKey(e: KeyboardEvent) {
      if (e.key === 'Escape') onCancel()
    }
    document.addEventListener('keydown', handleKey)
    return () => document.removeEventListener('keydown', handleKey)
  }, [open, onCancel])

  // Focus, as a modal dialog needs it: Cancel (the least destructive
  // choice) takes it on open, Tab and Shift+Tab stay inside the dialog, and
  // on close it goes back to what had it (the Delete button that opened
  // the modal) when that is still on the page.
  const titleId = useId()
  const bodyId = useId()
  const dialogRef = useRef<HTMLDivElement>(null)
  const cancelRef = useRef<HTMLButtonElement>(null)

  useEffect(() => {
    if (!open) return
    const previous = document.activeElement instanceof HTMLElement ? document.activeElement : null
    cancelRef.current?.focus()

    function trapTab(e: KeyboardEvent) {
      if (e.key !== 'Tab' || !dialogRef.current) return
      const focusable = Array.from(
        dialogRef.current.querySelectorAll<HTMLElement>('button:not([disabled]), [href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'),
      )
      if (focusable.length === 0) return
      const first = focusable[0]
      const last = focusable[focusable.length - 1]
      const inside = dialogRef.current.contains(document.activeElement)
      if (e.shiftKey && (document.activeElement === first || !inside)) {
        e.preventDefault()
        last.focus()
      } else if (!e.shiftKey && (document.activeElement === last || !inside)) {
        e.preventDefault()
        first.focus()
      }
    }
    document.addEventListener('keydown', trapTab)

    return () => {
      document.removeEventListener('keydown', trapTab)
      if (previous?.isConnected) previous.focus()
    }
  }, [open])

  // Block the browser back button while the modal is visible — the
  // user must pick a button. Cooperates with DrawerShell so closing
  // the modal does not close the drawer underneath.
  useModalHistoryLock(open)

  if (!open) return null

  async function handleConfirm() {
    setLoading(true)
    try {
      await onConfirm()
    } finally {
      setLoading(false)
    }
  }

  const content = (
    <div
      className="martis-modal-scrim"
      style={{ opacity: visible ? 1 : 0, transition: 'opacity 200ms ease' }}
      onClick={handleBackdropClose}
    >
      <div
        ref={dialogRef}
        role="dialog"
        aria-modal="true"
        aria-labelledby={titleId}
        aria-describedby={bodyId}
        className="martis-modal-surface"
        style={{ transform: visible ? 'scale(1)' : 'scale(0.95)', transition: 'transform 200ms ease' }}
        onClick={(e) => e.stopPropagation()}
      >
        <div className="martis-modal-head">
          <div className="flex items-center gap-3">
            {variant === 'restore'
              ? <ArrowCounterClockwiseIcon size={18} weight="bold" style={{ color: 'var(--martis-success)' }} />
              : <WarningIcon size={18} weight="fill" style={{ color: isSoftDelete ? 'var(--martis-warning)' : 'var(--martis-danger)' }} />}
            <h3 id={titleId} className="martis-modal-head-title">
              {variant === 'restore'
                ? tAct('restore')
                : (isSoftDelete ? tAct('archive') : tAct('delete'))} {resourceLabel}
            </h3>
          </div>
          <button
            type="button"
            onClick={onCancel}
            className="martis-modal-close"
            aria-label={tAct('cancel')}
          >
            <XIcon size={16} />
          </button>
        </div>

        <div id={bodyId} className="martis-modal-body">
          {confirmMessage ?? (
            variant === 'restore'
              ? tMsg('restore_confirm', 'Are you sure you want to restore this record?')
              : (isSoftDelete ? tMsg('archive_confirm') : tMsg('delete_confirm'))
          )}
        </div>

        <div className="martis-modal-foot">
          <button
            ref={cancelRef}
            type="button"
            onClick={onCancel}
            disabled={loading}
            className="martis-btn-secondary"
          >
            <XIcon size={14} />
            {tAct('cancel')}
          </button>
          <button
            type="button"
            onClick={() => void handleConfirm()}
            disabled={loading}
            className={
              variant === 'restore'
                ? 'martis-btn-success'
                : (isSoftDelete ? 'martis-btn-warning' : 'martis-btn-danger')
            }
          >
            {variant === 'restore'
              ? <ArrowCounterClockwiseIcon size={14} />
              : (isSoftDelete ? <ArrowCounterClockwiseIcon size={14} /> : <TrashIcon size={14} />)}
            {loading
              ? tAct('please_wait')
              : variant === 'restore'
                ? tAct('restore')
                : (isSoftDelete ? tAct('archive') : tAct('delete_permanent'))}
          </button>
        </div>
      </div>
    </div>
  )

  return createPortal(content, document.body)
}

if (!registry.has('component:DeleteModal')) {
  registry.register('component:DeleteModal', DefaultDeleteModal)
}

export function DeleteModal(props: DeleteModalProps) {
  const Component = registry.resolve<DeleteModalProps>(
    'component:DeleteModal',
    DefaultDeleteModal,
  )
  return <Component {...props} />
}
