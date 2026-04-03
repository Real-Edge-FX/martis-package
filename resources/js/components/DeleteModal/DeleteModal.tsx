import { useState } from 'react'
import { registry } from '@/lib/registry'
import { useTranslation } from 'react-i18next'

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

  return (
    <div
      className="fixed inset-0 z-50 flex items-center justify-center bg-black/50"
      onClick={onCancel}
      role="dialog"
      aria-modal="true"
      aria-labelledby="delete-modal-title"
    >
      <div
        className="mx-4 w-full max-w-md rounded-xl bg-white p-6 shadow-xl dark:bg-gray-900"
        onClick={(e) => e.stopPropagation()}
      >
        <div className="mb-4 flex items-center gap-3">
          <div className="flex h-10 w-10 items-center justify-center rounded-full bg-red-100 dark:bg-red-900/30">
            <span className="text-red-600 dark:text-red-400" aria-hidden="true">
              ⚠
            </span>
          </div>
          <h2
            id="delete-modal-title"
            className="text-lg font-semibold text-gray-900 dark:text-white"
          >
            {isSoftDelete ? tAct('archive') : tAct('delete')} {resourceLabel}
          </h2>
        </div>
        <p className="mb-6 text-sm text-gray-500 dark:text-gray-400">
          {isSoftDelete ? tMsg('archive_confirm') : tMsg('delete_confirm')}
        </p>
        <div className="flex justify-end gap-3">
          <button
            type="button"
            onClick={onCancel}
            disabled={loading}
            className="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700"
          >
            {tAct('cancel')}
          </button>
          <button
            type="button"
            onClick={handleConfirm}
            disabled={loading}
            className={[
              'rounded-md px-4 py-2 text-sm font-medium text-white transition-colors',
              isSoftDelete
                ? 'bg-amber-600 hover:bg-amber-700 disabled:opacity-60'
                : 'bg-red-600 hover:bg-red-700 disabled:opacity-60',
            ].join(' ')}
          >
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
