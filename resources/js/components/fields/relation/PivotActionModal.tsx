import { useState, useRef, useEffect, useLayoutEffect, useCallback } from 'react'
import { createPortal } from 'react-dom'
import { useQuery, useMutation } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { LightningIcon, WarningIcon, XIcon } from '@phosphor-icons/react'
import { api } from '@/lib/api'
import { pathSegment } from '@/lib/apiPath'
import { useModalHistoryLock } from '@/lib/historyLock'
import { hasOpenLayer } from '@/lib/escapeLayers'
import { useToast } from '@/contexts/ToastContext'
import { FieldInput } from '@/components/fields/FieldRenderer'
import { FieldWrapper } from '@/components/fields/FieldWrapper'
import { fieldDefaults } from '@/lib/fieldDefaults'
import { actionFieldsUrl, actionRecordQuery, mapActionErrors, postAction } from '@/components/Actions/actionRequest'
import { fieldErrorProps } from '@/lib/fieldErrors'
import type { ActionMeta } from '@/components/Actions/ActionModal'
import { ActionDryRunPreview } from '@/components/Actions/ActionDryRunPreview'
import type { FieldDefinition } from '@/types'
import { useNavigate } from 'react-router'
import { martisEventBus } from '@/lib/eventBus'
import { closeActionTab, handleActionResponse, openActionTab } from '@/components/Actions/actionResponse'
import { useActionResponseModal } from '@/components/Actions/ActionResponseModalHost'

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
  resourceKey,
  action,
  selectedIds,
  onSuccess,
  onClose,
}: {
  /** `/api/resources/{resource}/{id}/{belongs-to-many|morph-to-many}/{relationship}/actions` */
  actionsUrl: string
  /** The resource of the related records the action runs on, for `martis:action-executed`. */
  resourceKey: string
  action: ActionMeta
  selectedIds: Array<string | number>
  onSuccess: () => void
  onClose: () => void
}) {
  const { t } = useTranslation('actions')
  const { addToast } = useToast()
  const navigate = useNavigate()
  const showModal = useActionResponseModal()

  useModalHistoryLock(true)

  const [fieldValues, setFieldValues] = useState<Record<string, unknown>>({})
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({})
  // The last dry-run answer (`undefined` until Preview is used); a field
  // change clears it.
  const [preview, setPreview] = useState<unknown>(undefined)
  const [animVisible, setAnimVisible] = useState(false)
  const autoExecuted = useRef(false)
  // The tab of an opensInNewTab() action that skips the modal, opened on the
  // click while its fields load (`undefined`: none opened).
  const earlyTab = useRef<Window | null | undefined>(undefined)

  // The modal mounts per run, so autoExecuted needs no reset: resetting it in
  // this effect ran after the render that auto-executed, and the next render
  // ran the action again.
  useEffect(() => {
    requestAnimationFrame(() => setAnimVisible(true))
    // A modal closed while its fields load leaves no blank tab behind.
    const tabs = earlyTab
    return () => {
      closeActionTab(tabs.current)
      tabs.current = undefined
    }
  }, [])

  const handleBackdropClose = useCallback(() => {
    setAnimVisible(false)
    setTimeout(onClose, 200)
  }, [onClose])

  useEffect(() => {
    function handleKey(e: KeyboardEvent) {
      if (e.key === 'Escape') {
        // A picker open in the form (a layer) takes this Escape: the
        // modal closes on the next one.
        if (e.defaultPrevented || hasOpenLayer()) return
        // Taken: a drawer underneath leaves a handled Escape alone.
        e.preventDefault()
        onClose()
      }
    }
    document.addEventListener('keydown', handleKey)
    return () => document.removeEventListener('keydown', handleKey)
  }, [onClose])

  const actionUrl = `${actionsUrl}/${pathSegment(action.uriKey)}`
  // The related record a single-record run is on, named to the fields
  // endpoint so `fields()` can read it (`selectedModel()`).
  const recordId = !action.standalone && selectedIds.length === 1 ? selectedIds[0] : undefined

  const fieldsQuery = useQuery({
    queryKey: ['pivot-action-fields', actionsUrl, action.uriKey, recordId ?? null],
    queryFn: ({ signal }) =>
      api.get<{ data: { fields: FieldDefinition[] } }>(actionFieldsUrl(actionUrl, recordId), signal),
    enabled: !!action,
    // `fields()` reads the related record (`selectedModel()`), so the fields
    // an earlier opening cached may describe a record that has changed since:
    // every opening fetches again, and the defaults seed from that answer.
    staleTime: 0,
    refetchOnMount: 'always',
  })

  const fields = fieldsQuery.data?.data?.fields ?? []

  // The defaults seed the values once, from the fields this opening fetched:
  // the modal mounts per opening, and a refetch of the fields (focus) must
  // not wipe what the user typed.
  const [seeded, setSeeded] = useState(false)
  useEffect(() => {
    if (seeded || fieldsQuery.data === undefined || fieldsQuery.isFetching) return
    setSeeded(true)
    setFieldValues(fieldDefaults(fields))
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [seeded, fieldsQuery.data, fieldsQuery.isFetching])

  const executeMutation = useMutation({
    // Each run starts without the errors of the last one, as every form
    // clears its errors before a save.
    onMutate: () => setFieldErrors({}),
    // `tab`: the blank tab of an `opensInNewTab()` action, opened on the click.
    // `auto` marks the run of an action that skips the modal.
    mutationFn: (params: { dryRun?: boolean; tab?: Window | null; auto?: boolean }) =>
      postAction(actionUrl, { resources: selectedIds, fields: fieldValues, dryRun: params.dryRun }),
    onSuccess: (res, params) => {
      if (params.dryRun) {
        setPreview(res?.data?.preview ?? null)
        return
      }

      martisEventBus.emit('martis:action-executed', { resourceKey, action: action.uriKey, ids: selectedIds.map(String) })

      // The same answers as the resource action modal (v2.3.0); a pivot
      // panel has no drawer to open, so openCreate/openDetail/openUpdate
      // fall back to a success toast.
      handleActionResponse(res?.data, { t, addToast, navigate, hide: onClose, refresh: onSuccess, showModal, tab: params.tab })
    },
    onError: (err: Error, params) => {
      closeActionTab(params.tab)
      // A run without a modal has nothing on screen to retry from: close it
      // so a new click runs it again. The toast below says what failed.
      if (params.auto) onClose()
      const mapped = mapActionErrors(err)
      if (Object.keys(mapped).length > 0) {
        setFieldErrors(mapped)
        addToast('error', err.message || t('action_failed'))
        return
      }
      addToast('error', err.message ?? t('action_failed'))
    },
  })

  const hasFields = fields.length > 0
  // A dry-run action opens the modal so Preview can be offered before it runs.
  const needsConfirmation = action.withConfirmation || hasFields || action.supportsDryRun

  const { mutate: execute } = executeMutation
  // Pending until the fields of this opening arrive: cached ones from an
  // earlier opening are being fetched again (see the query).
  const fieldsPending = fieldsQuery.isPending || (fieldsQuery.isFetching && !seeded)

  // Run an action that needs no modal, as the resource action modal does:
  // in a layout effect of the commit the click caused, so an opensInNewTab()
  // tab still opens, and only once its fields have loaded.
  useLayoutEffect(() => {
    if (autoExecuted.current || action.withConfirmation || action.supportsDryRun) return
    if (action.opensInNewTab && earlyTab.current === undefined) earlyTab.current = openActionTab()
    if (fieldsPending) return

    const tab = earlyTab.current
    earlyTab.current = undefined
    if (hasFields) {
      closeActionTab(tab)
      return
    }
    autoExecuted.current = true
    execute({ tab, auto: true })
  }, [action, fieldsPending, hasFields, execute])

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
          {action.withConfirmation && (
            <p className="mb-4">{action.confirmText ?? t('confirm_run')}</p>
          )}
          {hasFields && (
            <div className="space-y-4">
              {fields.map((f) => (
                <FieldWrapper
                  key={f.attribute}
                  htmlFor={f.attribute}
                  label={f.label}
                  required={f.required}
                  tooltip={f.tooltip}
                  help={f.helpText}
                >
                  <FieldInput
                    field={f}
                    value={fieldValues[f.attribute] ?? ''}
                    onChange={(val: unknown) => {
                      setFieldValues((prev) => ({ ...prev, [f.attribute]: val }))
                      setPreview(undefined)
                    }}
                    {...fieldErrorProps(fieldErrors, f.attribute)}
                    context="create"
                    actionEndpoint={actionUrl}
                    actionQuery={actionRecordQuery(recordId)}
                  />
                </FieldWrapper>
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
            onClick={() => executeMutation.mutate({ tab: action.opensInNewTab ? openActionTab() : undefined })}
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
