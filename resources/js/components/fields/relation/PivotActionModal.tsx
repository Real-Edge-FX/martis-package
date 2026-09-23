import { useState, useRef, useEffect, useCallback } from 'react'
import { createPortal } from 'react-dom'
import { useQuery, useMutation } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { LightningIcon, WarningIcon, XIcon } from '@phosphor-icons/react'
import { api, ApiError } from '@/lib/api'
import { useModalHistoryLock } from '@/lib/historyLock'
import { useToast } from '@/contexts/ToastContext'
import { FieldInput } from '@/components/fields/FieldRenderer'
import type { ActionMeta } from '@/components/Actions/ActionModal'
import { ActionDryRunPreview } from '@/components/Actions/ActionDryRunPreview'
import type { FieldDefinition } from '@/types'

// -------------------------------------------------------------------------
// Pivot action modal — shared by the BelongsToMany and MorphToMany panels.
//
// `actionsUrl` is the panel's pivot actions endpoint,
// `/api/resources/{resource}/{id}/{belongs-to-many|morph-to-many}/{relationship}/actions`.
// The action's fields come from `{actionsUrl}/{uriKey}/fields`, their
// relation pickers from `{actionsUrl}/{uriKey}/relatable/{attribute}`, and the
// run posts to `{actionsUrl}/{uriKey}`, so an action declared on the field with
// `->actions()` (which is not one of the resource's own actions) resolves
// through the same relationship the panel lists it for. A `withDryRun()`
// action gets a Preview button that posts the same body with `dryRun: true`
// and shows the answer of its `dryRun()`, as the resource action modal does.
// -------------------------------------------------------------------------

// PHP ModalSize enum value → CSS max-width
const MODAL_SIZE_MAP: Record<string, string> = {
  sm: '24rem',
  md: '28rem',
  lg: '32rem',
  xl: '36rem',
  '2xl': '42rem',
  '3xl': '48rem',
  '4xl': '56rem',
  '5xl': '64rem',
  '6xl': '72rem',
  '7xl': '80rem',
}

export function PivotActionModal({
  actionsUrl,
  action,
  selectedIds,
  onSuccess,
  onClose,
}: {
  /** `/api/resources/{resource}/{id}/{belongs-to-many|morph-to-many}/{relationship}/actions` */
  actionsUrl: string
  action: ActionMeta
  selectedIds: Array<string | number>
  onSuccess: () => void
  onClose: () => void
}) {
  const { t } = useTranslation('actions')
  const { addToast } = useToast()

  useModalHistoryLock(true)

  const [fieldValues, setFieldValues] = useState<Record<string, unknown>>({})
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({})
  // The last dry-run answer (`undefined` until Preview is used); a field
  // change clears it.
  const [preview, setPreview] = useState<unknown>(undefined)
  const [animVisible, setAnimVisible] = useState(false)
  const autoExecuted = useRef(false)

  useEffect(() => {
    requestAnimationFrame(() => setAnimVisible(true))
    autoExecuted.current = false
  }, [])

  const handleBackdropClose = useCallback(() => {
    setAnimVisible(false)
    setTimeout(onClose, 200)
  }, [onClose])

  useEffect(() => {
    function handleKey(e: KeyboardEvent) {
      if (e.key === 'Escape') onClose()
    }
    document.addEventListener('keydown', handleKey)
    return () => document.removeEventListener('keydown', handleKey)
  }, [onClose])

  const actionUrl = `${actionsUrl}/${action.uriKey}`

  const fieldsQuery = useQuery({
    queryKey: ['pivot-action-fields', actionsUrl, action.uriKey],
    queryFn: ({ signal }) =>
      api.get<{ data: { fields: FieldDefinition[] } }>(`${actionUrl}/fields`, signal),
    enabled: !!action,
  })

  const fields = fieldsQuery.data?.data?.fields ?? []

  const executeMutation = useMutation({
    mutationFn: (params: { dryRun?: boolean }) =>
      api.post<{ data: { type?: string; data?: Record<string, unknown>; preview?: unknown } }>(
        actionUrl,
        {
          resources: selectedIds,
          fields: fieldValues,
          dryRun: params.dryRun ?? false,
        }
      ),
    onSuccess: (res, params) => {
      if (params.dryRun) {
        setPreview(res?.data?.preview ?? null)
        return
      }

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
          default:
            addToast('success', t('action_success'))
        }
      } else {
        addToast('success', t('action_success'))
      }
      onSuccess()
    },
    onError: (err: Error) => {
      if (err instanceof ApiError && err.errors && err.errors.length > 0) {
        const mapped: Record<string, string> = {}
        for (const e of err.errors) {
          const fieldKey = e.field.replace(/^fields\./, '')
          if (!mapped[fieldKey]) mapped[fieldKey] = e.message
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

  const hasFields = fields.length > 0
  // A dry-run action opens the modal so Preview can be offered before it runs.
  const needsConfirmation = action.withConfirmation || hasFields || action.supportsDryRun

  // Auto-execute if no confirmation or fields needed (only once)
  if (!needsConfirmation && !autoExecuted.current && !executeMutation.isPending) {
    autoExecuted.current = true
    setTimeout(() => executeMutation.mutate({}), 0)
    return null
  }

  if (!needsConfirmation) return null

  const modalWidth = MODAL_SIZE_MAP[action.modalSize ?? 'md'] ?? MODAL_SIZE_MAP['md']

  return createPortal((
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
            {action.destructive
              ? <WarningIcon size={18} weight="fill" style={{ color: 'var(--martis-danger)' }} />
              : <LightningIcon size={18} weight="fill" style={{ color: 'var(--martis-accent)' }} />}
            <h3 className="martis-modal-head-title">{action.name}</h3>
          </div>
          <button
            type="button"
            onClick={onClose}
            className="martis-modal-close"
            aria-label={action.cancelButtonText ?? t('cancel')}
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
              {fields.map((f) => (
                <div key={f.attribute}>
                  <label className="mb-1 block text-sm font-medium" style={{ color: 'var(--martis-text)' }}>
                    {f.label}
                    {f.required && <span className="ml-1" style={{ color: 'var(--martis-danger)' }}>*</span>}
                  </label>
                  <FieldInput
                    field={f}
                    value={fieldValues[f.attribute] ?? ''}
                    onChange={(val: unknown) => {
                      setFieldValues((prev) => ({ ...prev, [f.attribute]: val }))
                      setPreview(undefined)
                    }}
                    error={fieldErrors[f.attribute]}
                    context="create"
                    actionEndpoint={actionUrl}
                  />
                </div>
              ))}
            </div>
          )}

          {preview !== undefined && <ActionDryRunPreview preview={preview} />}
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
            onClick={onClose}
            disabled={executeMutation.isPending}
            className="martis-btn-secondary"
          >
            <XIcon size={14} />
            {action.cancelButtonText ?? t('cancel')}
          </button>
          <button
            type="button"
            onClick={() => executeMutation.mutate({})}
            disabled={executeMutation.isPending}
            className={action.destructive ? 'martis-btn-danger' : 'martis-btn-primary'}
          >
            <LightningIcon size={14} />
            {executeMutation.isPending
              ? t('please_wait')
              : (action.confirmButtonText ?? t('run_action'))}
          </button>
        </div>
      </div>
    </div>
  ), document.body)
}
