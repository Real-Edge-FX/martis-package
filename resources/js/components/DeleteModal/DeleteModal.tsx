import { useState, useEffect, useCallback } from 'react'
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
}

function DefaultDeleteModal({
  open,
  resourceLabel,
  isSoftDelete,
  onConfirm,
  onCancel,
  confirmMessage,
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
    <div style={{ position: 'fixed', inset: 0, zIndex: 9990 }} className="flex items-center justify-center">
      {/* Backdrop */}
      <div
        className="absolute inset-0 transition-opacity duration-200"
        style={{
          backgroundColor: visible ? 'rgba(0,0,0,0.4)' : 'rgba(0,0,0,0)',
        }}
        onClick={handleBackdropClose}
      />

      {/* Modal panel */}
      <div
        role="dialog"
        className="relative w-full max-w-md rounded-xl shadow-xl transition-all duration-200"
        style={{
          backgroundColor: 'var(--martis-card)',
          border: '1px solid var(--martis-border)',
          transform: visible ? 'scale(1)' : 'scale(0.95)',
          opacity: visible ? 1 : 0,
        }}
      >
        {/* Header */}
        <div
          className="flex items-center justify-between border-b px-6 py-4"
          style={{ borderColor: 'var(--martis-border)' }}
        >
          <div className="flex items-center gap-3">
            <div className="flex h-10 w-10 items-center justify-center rounded-full bg-red-100 dark:bg-red-900/30">
              <WarningIcon size={20} className="text-red-600 dark:text-red-400" weight="fill" />
            </div>
            <span className="text-lg font-semibold" style={{ color: 'var(--martis-text)' }}>
              {isSoftDelete ? tAct('archive') : tAct('delete')} {resourceLabel}
            </span>
          </div>
          <button
            type="button"
            onClick={onCancel}
            className="rounded-md p-1.5 transition-colors hover:bg-gray-100 dark:hover:bg-gray-800"
            style={{ color: 'var(--martis-text-muted)' }}
          >
            <XIcon size={16} />
          </button>
        </div>

        {/* Body */}
        <div className="px-6 py-4">
          <p className="text-sm" style={{ color: 'var(--martis-text-muted)' }}>
            {confirmMessage ?? (isSoftDelete ? tMsg('archive_confirm') : tMsg('delete_confirm'))}
          </p>
        </div>

        {/* Footer */}
        <div
          className="flex items-center justify-end gap-3 border-t px-6 py-4"
          style={{
            borderColor: 'var(--martis-border)',
            backgroundColor: 'var(--martis-surface)',
            borderRadius: '0 0 0.75rem 0.75rem',
          }}
        >
          <button
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
            className={isSoftDelete ? 'martis-btn-warning' : 'martis-btn-danger'}
          >
            {isSoftDelete ? <ArrowCounterClockwiseIcon size={14} /> : <TrashIcon size={14} />}
            {loading
              ? tAct('please_wait')
              : isSoftDelete
                ? tAct('archive')
                : tAct('delete_permanent')}
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
