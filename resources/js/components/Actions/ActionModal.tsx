import { useState, useEffect, useLayoutEffect, useCallback, useRef } from 'react'
import { createPortal } from 'react-dom'
import { useMutation, useQuery } from '@tanstack/react-query'
import { api, ApiError } from '@/lib/api'
import { apiPath } from '@/lib/apiPath'
import type { FieldDefinition } from '@/types'
import { FieldInput } from '@/components/fields/FieldRenderer'
import { fieldErrorProps } from '@/lib/fieldErrors'
import { useToast } from '@/contexts/ToastContext'
import { useTranslation } from 'react-i18next'
import { registry } from '@/lib/registry'
import { ResourceIcon } from '@/components/ResourceIcon'
import { LightningIcon, WarningIcon, XIcon } from '@phosphor-icons/react'
import { componentRegistry } from '@/lib/componentRegistry'
import { useModalHistoryLock } from '@/lib/historyLock'
import { ActionDryRunPreview } from './ActionDryRunPreview'
import { useNavigate } from 'react-router'
import { martisEventBus } from '@/lib/eventBus'
import { closeActionTab, handleActionResponse, openActionTab } from './actionResponse'
import { useActionResponseModal } from './ActionResponseModalHost'

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
  /** The run answers `openInNewTab`: the tab opens on the click (v2.15.0). */
  opensInNewTab?: boolean
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
  /** The relationship a panel runs the action through, sent as Nova does,
   *  so the run reaches only a record that relationship holds. */
  via?: ActionVia
  /** The lens the action runs from: its actions (its own `actions()`, or
   *  the resource's) are resolved from the lens, as in Nova. */
  lens?: string
}

/** `viaResource` / `viaResourceId` / `viaRelationship` of a panel's run. */
export interface ActionVia {
  viaResource: string
  viaResourceId: string | number
  viaRelationship: string
}

function DefaultActionModal({ resource, action, selectedIds, visible, onHide, onSuccess, onOpenCreate, onOpenDetail, onOpenUpdate, via, lens }: ActionModalProps) {
  // A lens's actions live under its own routes: an action only the lens
  // declares is unknown to the resource's.
  const actionUrl = !action
    ? ''
    : lens
      ? apiPath`/api/resources/${resource}/lenses/${lens}/actions/${action.uriKey}`
      : apiPath`/api/resources/${resource}/actions/${action.uriKey}`
  const { addToast } = useToast()
  const { t } = useTranslation('actions')
  const navigate = useNavigate()
  const showModal = useActionResponseModal()
  const [fieldValues, setFieldValues] = useState<Record<string, unknown>>({})
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({})
  // The last dry-run answer (`undefined` until Preview is used). A field
  // change clears it: it described the previous values.
  const [preview, setPreview] = useState<unknown>(undefined)
  const [animVisible, setAnimVisible] = useState(false)
  const autoExecuted = useRef(false)
  // The tab of an opensInNewTab() action that skips the modal, opened on the
  // click while its fields load (`undefined`: none opened).
  const earlyTab = useRef<Window | null | undefined>(undefined)

  useEffect(() => {
    if (visible) {
      requestAnimationFrame(() => setAnimVisible(true))
    } else {
      setAnimVisible(false)
      // Rearmed on close, not on open: an open-time reset ran after the
      // render that auto-executed, so the next render ran the action again.
      autoExecuted.current = false
      closeActionTab(earlyTab.current)
      earlyTab.current = undefined
    }
  }, [visible])

  const handleBackdropClose = useCallback(() => {
    setAnimVisible(false)
    setTimeout(onHide, 200)
  }, [onHide])

  useEffect(() => {
    if (!visible) return
    function handleKey(e: KeyboardEvent) {
      if (e.key === 'Escape') {
        // Taken: a drawer underneath leaves a handled Escape alone.
        e.preventDefault()
        onHide()
      }
    }
    document.addEventListener('keydown', handleKey)
    return () => document.removeEventListener('keydown', handleKey)
  }, [visible, onHide])

  // Block the browser back button while the modal is visible — the
  // user must pick a button. Cooperates with DrawerShell so a modal
  // opened on top of a drawer can close without also closing it.
  useModalHistoryLock(visible && !!action)

  const fieldsQuery = useQuery({
    queryKey: ['action-fields', resource, lens ?? null, action?.uriKey],
    queryFn: () =>
      api.get<{ data: { fields: FieldDefinition[] } }>(`${actionUrl}/fields`),
    enabled: visible && !!action,
  })

  const fields = fieldsQuery.data?.data?.fields ?? []

  useEffect(() => {
    setFieldValues({})
    setFieldErrors({})
    setPreview(undefined)
  }, [action?.uriKey, visible])

  const executeMutation = useMutation({
    // Each run starts without the errors of the last one, as every form
    // clears its errors before a save.
    onMutate: () => setFieldErrors({}),
    // `tab` is the blank tab an `opensInNewTab()` action opened on the click
    // (see runAction below); the answer sends it to the URL or closes it.
    // `auto` marks the run of an action that skips the modal.
    mutationFn: (params: { dryRun?: boolean; extraFields?: Record<string, unknown>; tab?: Window | null; auto?: boolean }) =>
      api.post<{ data: { type?: string; data?: Record<string, unknown>; preview?: unknown } }>(
        actionUrl,
        {
          resources: selectedIds,
          fields: { ...fieldValues, ...(params.extraFields ?? {}) },
          dryRun: params.dryRun ?? false,
          ...(via ?? {}),
        },
      ),
    onSuccess: (res, params) => {
      // A dry run answers with the action's preview and runs nothing: show
      // it and keep the modal open.
      if (params.dryRun) {
        setPreview(res?.data?.preview ?? null)
        return
      }

      // After every run, as Nova fires `action-executed` (v2.3.0).
      martisEventBus.emit('martis:action-executed', { resourceKey: resource, action: action?.uriKey ?? '', ids: selectedIds.map(String) })

      handleActionResponse(res?.data, {
        t,
        addToast,
        navigate,
        hide: onHide,
        refresh: onSuccess,
        showModal,
        onOpenCreate,
        onOpenDetail,
        onOpenUpdate,
        tab: params.tab,
      })
    },
    onError: (err: Error, params) => {
      closeActionTab(params.tab)
      // A run without a modal has nothing on screen to retry from: close it
      // so the parent drops the action (and its history lock), and a new
      // click runs it again. The toast below says what failed.
      if (params.auto) onHide()
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

  const { mutate: execute } = executeMutation
  const fieldsPending = fieldsQuery.isPending
  const fieldCount = fields.length

  // Run an action that needs no modal: no confirmation, no Preview, no
  // custom component and, once they have loaded, no fields. In a layout
  // effect of the commit the click caused, so the browser still sees the
  // click when an opensInNewTab() action opens its tab; the tab opens at
  // once and the run waits for the fields, which may still ask for the modal.
  useLayoutEffect(() => {
    if (!visible || !action || autoExecuted.current) return
    if (action.withConfirmation || action.supportsDryRun || action.customComponent) return
    if (action.opensInNewTab && earlyTab.current === undefined) earlyTab.current = openActionTab()
    if (fieldsPending) return

    const tab = earlyTab.current
    earlyTab.current = undefined
    if (fieldCount > 0) {
      // The fields ask for the modal: its Run Action opens the tab again.
      closeActionTab(tab)
      return
    }
    autoExecuted.current = true
    execute({ tab, auto: true })
  }, [visible, action, fieldsPending, fieldCount, execute])

  if (!visible || !action) return null

  // Run the action, opening its tab first when it declares opensInNewTab():
  // called from a click handler (or the render a click caused), so the
  // browser still sees the click and lets the tab open.
  const runAction = (extraFields?: Record<string, unknown>) =>
    executeMutation.mutate({ extraFields, tab: action.opensInNewTab ? openActionTab() : undefined })

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
        onExecute: (extraFields) => runAction(extraFields),
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
  // A dry-run action opens the modal so Preview can be offered before it runs.
  const needsConfirmation = action.withConfirmation || hasFields || action.supportsDryRun

  // No modal: the layout effect above runs the action (or waits for its fields).
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
          {/* Nova's default confirmText, so a confirmation never opens blank. */}
          {action.withConfirmation && (
            <p className="mb-4">{action.confirmText ?? t('confirm_run')}</p>
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
                    onChange={(val: unknown) => {
                      setFieldValues((prev) => ({ ...prev, [field.attribute]: val }))
                      setPreview(undefined)
                    }}
                    {...fieldErrorProps(fieldErrors, field.attribute)}
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
            onClick={onHide}
            disabled={executeMutation.isPending}
            className="martis-btn-secondary"
          >
            <XIcon size={14} />
            {cancelButton}
          </button>
          <button
            type="button"
            onClick={() => runAction()}
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
