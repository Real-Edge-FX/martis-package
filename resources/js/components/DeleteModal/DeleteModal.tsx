import { useState } from 'react'
import { registry } from '@/lib/registry'
import { useTranslation } from 'react-i18next'
import { Dialog } from 'primereact/dialog'
import { Button } from 'primereact/button'
import { Warning } from '@phosphor-icons/react'

export interface DeleteModalProps {
  open: boolean
  resourceLabel: string
  isSoftDelete: boolean
  onConfirm: () => Promise<void>
  onCancel: () => void
}

function DefaultDeleteModal({
  open,
  resourceLabel,
  isSoftDelete,
  onConfirm,
  onCancel,
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
    <div className="flex justify-end gap-3">
      <Button
        label={tAct('cancel')}
        onClick={onCancel}
        disabled={loading}
        className="p-button-outlined p-button-secondary"
      />
      <Button
        label={
          loading
            ? tAct('please_wait')
            : isSoftDelete
              ? tAct('archive')
              : tAct('delete_permanent')
        }
        onClick={() => void handleConfirm()}
        loading={loading}
        severity={isSoftDelete ? 'warning' : 'danger'}
      />
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
      appendTo="self"
    >
      <p className="text-sm text-gray-500 dark:text-gray-400">
        {isSoftDelete ? tMsg('archive_confirm') : tMsg('delete_confirm')}
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
