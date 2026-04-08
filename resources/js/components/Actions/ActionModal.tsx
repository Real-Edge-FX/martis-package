import { useState, useEffect, useCallback } from 'react'
import { createPortal } from 'react-dom'
import { useMutation, useQuery } from '@tanstack/react-query'
import { api } from '@/lib/api'
import { ApiError } from '@/lib/api'
import type { FieldDefinition } from '@/types'
import { FieldInput } from '@/components/fields'
import { useToast } from '@/contexts/ToastContext'
import { useTranslation } from 'react-i18next'
import { registry } from '@/lib/registry'
import { ResourceIcon } from '@/components/ResourceIcon'
import { Lightning, Warning, X } from '@phosphor-icons/react'

export interface ActionMeta {
  uriKey: string
  name: string
  icon: string | null
  group: string | null
  destructive: boolean
  showOnIndex: boolean
  showOnDetail: boolean
  showInline: boolean
  executionMode: string
  standalone: boolean
  sole: boolean
  queued: boolean
  withConfirmation: boolean
  confirmText: string | null
  confirmButtonText: string | null
  cancelButtonText: string | null
  modalSize: string
  supportsDryRun: boolean
  customComponent: string | null
  customComponentProps: Record<string, unknown>
  logEvents: boolean
}

interface ActionModalProps {
  resource: string
  action: ActionMeta | null
  selectedIds: Array<string | number>
  visible: boolean
  onHide: () => void
  onSuccess: () => void
}

function DefaultActionModal({ resource, action, selectedIds, visible, onHide, onSuccess }: ActionModalProps) {
  const { addToast } = useToast()
  const { t } = useTranslation('actions')
  const [fieldValues, setFieldValues] = useState<Record<string, unknown>>({})
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({})
  const [animVisible, setAnimVisible] = useState(false)

  // Animation
  useEffect(() => {
    if (visible) {
      requestAnimationFrame(() => setAnimVisible(true))
    } else {
      setAnimVisible(false)
    }
  }, [visible])

  const handleBackdropClose = useCallback(() => {
    setAnimVisible(false)
    setTimeout(onHide, 200)
  }, [onHide])

  // Escape key
  useEffect(() => {
    if (!visible) return
    function handleKey(e: KeyboardEvent) {
      if (e.key === 'Escape') onHide()
    }
    document.addEventListener('keydown', handleKey)
    return () => document.removeEventListener('keydown', handleKey)
  }, [visible, onHide])

  // Fetch action fields
  const fieldsQuery = useQuery({
    queryKey: ['action-fields', resource, action?.uriKey],
    queryFn: () =>
      api.get<{ data: { fields: FieldDefinition[] } }>(
        `/api/resources/${resource}/actions/${action!.uriKey}/fields`,
      ),
    enabled: visible && !!action,
  })

  const fields = fieldsQuery.data?.data?.fields ?? []

  // Reset values when action changes
  useEffect(() => {
    setFieldValues({})
    setFieldErrors({})
  }, [action?.uriKey, visible])

  // Execute action mutation
  const executeMutation = useMutation({
    mutationFn: (params: { dryRun?: boolean }) =>
      api.post<{ data: { type: string; data: Record<string, unknown> } }>(
        `/api/resources/${resource}/actions/${action!.uriKey}`,
        {
          resources: selectedIds,
          fields: fieldValues,
          dryRun: params.dryRun ?? false,
        },
      ),
    onSuccess: (res) => {
      const responseData = res?.data
      if (responseData) {
        const data = responseData.data

        switch (responseData.type) {
          case 'message':
            addToast('success', (data?.message as string) ?? t('action_success'))
            break
          case 'danger':
            addToast('error', (data?.message as string) ?? t('action_failed'))
            break
          case 'redirect':
            if (data?.url) window.location.href = data.url as string
            return
          case 'visit':
            if (data?.path) window.location.href = data.path as string
            return
          case 'openInNewTab':
            if (data?.url) window.open(data.url as string, '_blank')
            break
          case 'download':
            if (data?.url) window.location.href = data.url as string
            break
          default:
            addToast('success', t('action_success'))
        }
      } else {
        addToast('success', t('action_success'))
      }
      onHide()
      onSuccess()
    },
    onError: (err: ApiError) => {
      if (err instanceof ApiError && err.errors) {
        setFieldErrors(err.errorsByField())
      }
      addToast('error', err.message ?? t('action_failed'))
    },
  })

  if (!visible || !action) return null

  const hasFields = fields.length > 0
  const needsConfirmation = action.withConfirmation || hasFields

  // Auto-execute if no confirmation or fields needed
  if (!needsConfirmation && !executeMutation.isPending && !executeMutation.isSuccess) {
    executeMutation.mutate({})
    return null
  }

  const modalWidth =
    action.modalSize === 'fullscreen' ? '100vw' :
    action.modalSize === '7xl' ? '80rem' :
    action.modalSize === '6xl' ? '72rem' :
    action.modalSize === '5xl' ? '64rem' :
    action.modalSize === '4xl' ? '56rem' :
    action.modalSize === '3xl' ? '48rem' :
    action.modalSize === '2xl' ? '42rem' :
    action.modalSize === 'xl' ? '36rem' :
    action.modalSize === 'lg' ? '32rem' :
    action.modalSize === 'sm' ? '20rem' :
    '28rem'

  const confirmButton = action.confirmButtonText ?? (action.destructive ? t('confirm_destructive') : t('run_action'))
  const cancelButton = action.cancelButtonText ?? t('cancel')

  /** Render the action icon — uses Phosphor ResourceIcon if set, falls back to Lightning/Warning. */
  function renderActionIcon() {
    if (action!.icon) {
      return <ResourceIcon iconName={action!.icon} size={20} weight="fill" />
    }
    if (action!.destructive) {
      return <Warning size={20} className="text-red-600 dark:text-red-400" weight="fill" />
    }
    return <Lightning size={20} className="text-indigo-600 dark:text-indigo-400" weight="fill" />
  }

  const content = (
    <div style={{ position: 'fixed', inset: 0, zIndex: 9990 }} className="flex items-center justify-center">
      {/* Backdrop */}
      <div
        className="absolute inset-0 transition-opacity duration-200"
        style={{
          backgroundColor: animVisible ? 'rgba(0,0,0,0.4)' : 'rgba(0,0,0,0)',
        }}
        onClick={handleBackdropClose}
      />

      {/* Modal panel */}
      <div
        role="dialog"
        className="relative w-full rounded-xl shadow-xl transition-all duration-200"
        style={{
          backgroundColor: 'var(--martis-card)',
          border: action.destructive ? '1px solid rgba(220,38,38,0.4)' : '1px solid var(--martis-border)',
          borderTop: action.destructive ? '3px solid #dc2626' : undefined,
          maxWidth: modalWidth,
          transform: animVisible ? 'scale(1)' : 'scale(0.95)',
          opacity: animVisible ? 1 : 0,
        }}
      >
        {/* Header */}
        <div
          className="flex items-center justify-between border-b px-6 py-4"
          style={{
            borderColor: action.destructive ? 'rgba(220,38,38,0.2)' : 'var(--martis-border)',
            backgroundColor: action.destructive ? 'rgba(220,38,38,0.05)' : undefined,
          }}
        >
          <div className="flex items-center gap-3">
            <div
              className="flex h-10 w-10 items-center justify-center rounded-full"
              style={{
                backgroundColor: action.destructive
                  ? 'rgba(220,38,38,0.1)'
                  : 'rgba(99,102,241,0.1)',
                color: action.destructive ? '#dc2626' : '#6366f1',
              }}
            >
              {renderActionIcon()}
            </div>
            <span className="text-lg font-semibold" style={{ color: 'var(--martis-text)' }}>
              {action.name}
            </span>
          </div>
          <button
            type="button"
            onClick={onHide}
            className="rounded-md p-1.5 transition-colors hover:bg-gray-100 dark:hover:bg-gray-800"
            style={{ color: 'var(--martis-text-muted)' }}
          >
            <X size={16} />
          </button>
        </div>

        {/* Body */}
        <div className="px-6 py-4">
          {action.confirmText && (
            <p className="mb-4 text-sm" style={{ color: 'var(--martis-text-muted)' }}>
              {action.confirmText}
            </p>
          )}

          {hasFields && (
            <div className="space-y-4">
              {fields.map((field) => (
                <div key={field.attribute}>
                  <label className="mb-1 block text-sm font-medium" style={{ color: 'var(--martis-text)' }}>
                    {field.label}
                    {field.required && <span className="ml-1 text-red-500">*</span>}
                  </label>
                  <FieldInput
                    field={field}
                    value={fieldValues[field.attribute] ?? ''} 
                    onChange={(val: unknown) =>
                      setFieldValues((prev) => ({ ...prev, [field.attribute]: val }))
                    }
                    error={fieldErrors[field.attribute]}
                    context="create"
                  />
                  {fieldErrors[field.attribute] && (
                    <p className="mt-1 text-sm text-red-500">{fieldErrors[field.attribute]}</p>
                  )}
                </div>
              ))}
            </div>
          )}
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
          {action.supportsDryRun && (
            <button
              type="button"
              onClick={() => executeMutation.mutate({ dryRun: true })}
              disabled={executeMutation.isPending}
              className="inline-flex items-center gap-2 rounded-lg border px-4 py-2 text-sm font-medium transition-colors hover:opacity-90 disabled:opacity-50"
              style={{
                backgroundColor: 'var(--martis-input-bg)',
                borderColor: 'var(--martis-border)',
                color: 'var(--martis-text)',
              }}
            >
              {t('preview')}
            </button>
          )}
          <button
            type="button"
            onClick={onHide}
            disabled={executeMutation.isPending}
            className="inline-flex items-center gap-2 rounded-lg border px-4 py-2 text-sm font-medium transition-colors hover:opacity-90 disabled:opacity-50"
            style={{
              backgroundColor: 'var(--martis-input-bg)',
              borderColor: 'var(--martis-border)',
              color: 'var(--martis-text)',
            }}
          >
            <X size={14} />
            {cancelButton}
          </button>
          <button
            type="button"
            onClick={() => executeMutation.mutate({})}
            disabled={executeMutation.isPending}
            className="inline-flex items-center gap-2 rounded-lg px-4 py-2 text-sm font-medium text-white transition-colors hover:opacity-90 disabled:opacity-50"
            style={{
              backgroundColor: action.destructive ? '#dc2626' : 'var(--martis-accent)',
            }}
          >
            <Lightning size={14} />
            {executeMutation.isPending ? t('please_wait') : confirmButton}
          </button>
        </div>
      </div>
    </div>
  )

  return createPortal(content, document.body)
}

if (!registry.has('component:ActionModal')) {
  registry.register('component:ActionModal', DefaultActionModal)
}

export function ActionModal(props: ActionModalProps) {
  const Component = registry.resolve<ActionModalProps>(
    'component:ActionModal',
    DefaultActionModal,
  )
  return <Component {...props} />
}
