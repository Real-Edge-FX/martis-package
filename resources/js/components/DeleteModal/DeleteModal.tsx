import { useState } from 'react'
import { registry } from '@/lib/registry'
import { useTranslation } from 'react-i18next'
import { Dialog } from 'primereact/dialog'
import { Warning, Trash, ArrowCounterClockwise, X } from '@phosphor-icons/react'

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
  const { t: tAct } = useTranslation('actions')
  const { t: tMsg } = useTranslation('messages')

  if (!open) return null

  async function handleConfirm() {
    setLoading(true)
    try {
      await onConfirm()
    } finally {
      setLoading(false)
    }
  }

  const header = (
    <div className="flex items-center gap-3">
      <div className="flex h-10 w-10 items-center justify-center rounded-full bg-red-100 dark:bg-red-900/30">
        <Warning size={20} className="text-red-600 dark:text-red-400" weight="fill" />
      </div>
      <span className="text-lg font-semibold">
        {isSoftDelete ? tAct('archive') : tAct('delete')} {resourceLabel}
      </span>
    </div>
  )

  const footer = (
    <div className="flex justify-end gap-3 pt-2">
      <button
        type="button"
        onClick={onCancel}
        disabled={loading}
        className="inline-flex items-center gap-2 rounded-lg border px-4 py-2 text-sm font-medium transition-colors hover:opacity-90 disabled:opacity-50"
        style={{
          backgroundColor: 'var(--martis-input-bg)',
          borderColor: 'var(--martis-border)',
          color: 'var(--martis-text)',
        }}
      >
        <X size={14} />
        {tAct('cancel')}
      </button>
      <button
        type="button"
        onClick={() => void handleConfirm()}
        disabled={loading}
        className="inline-flex items-center gap-2 rounded-lg px-4 py-2 text-sm font-medium text-white transition-colors hover:opacity-90 disabled:opacity-50"
        style={{
          backgroundColor: isSoftDelete ? '#f59e0b' : '#dc2626',
        }}
      >
        {isSoftDelete ? <ArrowCounterClockwise size={14} /> : <Trash size={14} />}
        {loading
          ? tAct('please_wait')
          : isSoftDelete
            ? tAct('archive')
            : tAct('delete_permanent')}
      </button>
    </div>
  )

  return (
    <Dialog
      visible={open}
      onHide={onCancel}
      header={header}
      footer={footer}
      style={{ width: '28rem' }}
      breakpoints={{ '640px': '90vw' }}
      modal
    >
      <p className="text-sm" style={{ color: 'var(--martis-text-muted)' }}>
        {confirmMessage ?? (isSoftDelete ? tMsg('archive_confirm') : tMsg('delete_confirm'))}
      </p>
    </Dialog>
  )
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
