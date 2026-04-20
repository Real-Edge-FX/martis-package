import { useState, useEffect, useCallback, useRef } from 'react'
import { createPortal } from 'react-dom'
import { useMutation, useQuery } from '@tanstack/react-query'
import { api, ApiError } from '@/lib/api'
import type { FieldDefinition } from '@/types'
import { FieldInput } from '@/components/fields/FieldRenderer'
import { useToast } from '@/contexts/ToastContext'
import { useTranslation } from 'react-i18next'
import { registry } from '@/lib/registry'
import { ResourceIcon } from '@/components/ResourceIcon'
import { LightningIcon, WarningIcon, XIcon } from '@phosphor-icons/react'
import { componentRegistry } from '@/lib/componentRegistry'
import { useModalHistoryLock } from '@/lib/historyLock'

export interface ActionMeta {
  uriKey: string
  name: string
  icon: string | null
  showIcon: boolean
  iconColor: string | null
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
  isPivotAction: boolean
  pivotLabel: string | null
}

/**
 * Props injected into custom action components when they take full control.
 * The component receives everything it needs to manage its own UI and lifecycle.
 */
export interface CustomActionComponentProps {
  /** The action metadata */
  action: ActionMeta
  /** The resource key (e.g. 'posts') */
  resource: string
  /** Selected record IDs */
  selectedIds: Array<string | number>
  /** Custom props defined via .component('key', props) in PHP */
  componentProps: Record<string, unknown>
  /** Update field values that will be sent when executing the action */
  onFieldsChange: (fields: Record<string, unknown>) => void
  /** Execute the action with current field values */
  onExecute: (extraFields?: Record<string, unknown>) => void
  /** Close/dismiss the action UI */
  onClose: () => void
  /** Whether the action is currently executing */
  isExecuting: boolean
}

interface ActionModalProps {
  onOpenCreate?: (resource: string) => void
  onOpenDetail?: (resource: string, recordId: string | number) => void
  onOpenUpdate?: (resource: string, recordId: string | number) => void
  resource: string
  action: ActionMeta | null
  selectedIds: Array<string | number>
  visible: boolean
  onHide: () => void
  onSuccess: () => void
}

function DefaultActionModal({ resource, action, selectedIds, visible, onHide, onSuccess, onOpenCreate, onOpenDetail, onOpenUpdate }: ActionModalProps) {
  const { addToast } = useToast()
  const { t } = useTranslation('actions')
  const [fieldValues, setFieldValues] = useState<Record<string, unknown>>({})
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({})
  const [animVisible, setAnimVisible] = useState(false)
  const autoExecuted = useRef(false)

  useEffect(() => {
    if (visible) {
      requestAnimationFrame(() => setAnimVisible(true))
      autoExecuted.current = false
    } else {
      setAnimVisible(false)
    }
  }, [visible])

  const handleBackdropClose = useCallback(() => {
    setAnimVisible(false)
    setTimeout(onHide, 200)
  }, [onHide])

  useEffect(() => {
    if (!visible) return
    function handleKey(e: KeyboardEvent) {
      if (e.key === 'Escape') onHide()
    }
    document.addEventListener('keydown', handleKey)
    return () => document.removeEventListener('keydown', handleKey)
  }, [visible, onHide])

  // Block the browser back button while the modal is visible — the
  // user must pick a button. Cooperates with DrawerShell so a modal
  // opened on top of a drawer can close without also closing it.
  useModalHistoryLock(visible && !!action)

  const fieldsQuery = useQuery({
    queryKey: ['action-fields', resource, action?.uriKey],
    queryFn: () =>
      api.get<{ data: { fields: FieldDefinition[] } }>(
        `/api/resources/${resource}/actions/${action!.uriKey}/fields`,
      ),
    enabled: visible && !!action,
  })

  const fields = fieldsQuery.data?.data?.fields ?? []

  useEffect(() => {
    setFieldValues({})
    setFieldErrors({})
  }, [action?.uriKey, visible])

  const executeMutation = useMutation({
    mutationFn: (params: { dryRun?: boolean; extraFields?: Record<string, unknown> }) =>
      api.post<{ data: { type: string; data: Record<string, unknown> } }>(
        `/api/resources/${resource}/actions/${action!.uriKey}`,
        {
          resources: selectedIds,
          fields: { ...fieldValues, ...(params.extraFields ?? {}) },
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
          case 'openCreate':
            if (data?.resource) {
              onHide()
              onOpenCreate?.(data.resource as string)
              return
            }
            break
          case 'openDetail':
            if (data?.resource && data?.recordId != null) {
              onHide()
              onOpenDetail?.(data.resource as string, data.recordId as string | number)
              return
            }
            break
          case 'openUpdate':
            if (data?.resource && data?.recordId != null) {
              onHide()
              onOpenUpdate?.(data.resource as string, data.recordId as string | number)
              return
            }
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
    onError: (err: Error) => {
      if (err instanceof ApiError && err.errors && err.errors.length > 0) {
        const mapped: Record<string, string> = {}
        for (const e of err.errors) {
          const fieldKey = e.field.replace(/^fields\./, '')
          if (!mapped[fieldKey]) {
            mapped[fieldKey] = e.message
          }
        }
        if (Object.keys(mapped).length > 0) {
          setFieldErrors(mapped)
          addToast('error', err.message || t('action_failed'))
          return
        }
      }
      addToast('error', (err instanceof ApiError ? err.message : err.message) ?? t('action_failed'))
    },
  })

  if (!visible || !action) return null

  // -----------------------------------------------------------------------
  // Custom component: full control mode — no modal wrapper at all.
  // The custom component manages its own UI, layout, and lifecycle.
  // -----------------------------------------------------------------------
  if (action.customComponent) {
    const CustomComp = componentRegistry.resolve(action.customComponent)
    if (CustomComp) {
      const C = CustomComp as React.ComponentType<CustomActionComponentProps>
      const customProps: CustomActionComponentProps = {
        action,
        resource,
        selectedIds,
        componentProps: action.customComponentProps ?? {},
        onFieldsChange: (newFields) => setFieldValues((prev) => ({ ...prev, ...newFields })),
        onExecute: (extraFields) => executeMutation.mutate({ extraFields }),
        onClose: onHide,
        isExecuting: executeMutation.isPending,
      }
      return createPortal(
        <div style={{ position: 'fixed', inset: 0, zIndex: 9990 }}>
          <C {...customProps} />
        </div>,
        document.body,
      )
    }
    return null
  }

  const hasFields = fields.length > 0
  const needsConfirmation = action.withConfirmation || hasFields

  // Auto-execute if no confirmation or fields needed (only once)
  if (!needsConfirmation && !autoExecuted.current && !executeMutation.isPending) {
    autoExecuted.current = true
    setTimeout(() => executeMutation.mutate({}), 0)
    return null
  }

  if (!needsConfirmation) return null

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

  function renderActionIcon() {
    if (action!.icon) {
      return <ResourceIcon iconName={action!.icon} size={18} weight="fill" />
    }
    if (action!.destructive) {
      return <WarningIcon size={18} weight="fill" style={{ color: 'var(--martis-danger)' }} />
    }
    return <LightningIcon size={18} weight="fill" style={{ color: 'var(--martis-accent)' }} />
  }

  const content = (
    <div
      className="martis-modal-scrim"
      style={{ opacity: animVisible ? 1 : 0, transition: 'opacity 200ms ease' }}
      onClick={handleBackdropClose}
    >
      <div
        role="dialog"
        aria-modal="true"
        className="martis-modal-surface"
        style={{
          maxWidth: modalWidth,
          transform: animVisible ? 'scale(1)' : 'scale(0.95)',
          transition: 'transform 200ms ease',
          borderTop: action.destructive ? '3px solid var(--martis-danger)' : undefined,
        }}
        onClick={(e) => e.stopPropagation()}
      >
        <div className="martis-modal-head">
          <div className="flex items-center gap-3">
            {renderActionIcon()}
            <h3 className="martis-modal-head-title">{action.name}</h3>
          </div>
          <button
            type="button"
            onClick={onHide}
            className="martis-modal-close"
            aria-label={cancelButton}
          >
            <XIcon size={16} />
          </button>
        </div>

        <div className="martis-modal-body">
          {action.confirmText && (
            <p className="mb-4">{action.confirmText}</p>
          )}

          {hasFields && (
            <div className="space-y-4">
              {fields.map((field) => (
                <div key={field.attribute}>
                  <label className="mb-1 block text-sm font-medium" style={{ color: 'var(--martis-text)' }}>
                    {field.label}
                    {field.required && <span className="ml-1" style={{ color: 'var(--martis-danger)' }}>*</span>}
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
                </div>
              ))}
            </div>
          )}
        </div>

        <div className="martis-modal-foot">
          {action.supportsDryRun && (
            <button
              type="button"
              onClick={() => executeMutation.mutate({ dryRun: true })}
              disabled={executeMutation.isPending}
              className="martis-btn-secondary"
            >
              {t('preview')}
            </button>
          )}
          <button
            type="button"
            onClick={onHide}
            disabled={executeMutation.isPending}
            className="martis-btn-secondary"
          >
            <XIcon size={14} />
            {cancelButton}
          </button>
          <button
            type="button"
            onClick={() => executeMutation.mutate({})}
            disabled={executeMutation.isPending}
            className={action.destructive ? 'martis-btn-danger' : 'martis-btn-primary'}
          >
            <LightningIcon size={14} />
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
