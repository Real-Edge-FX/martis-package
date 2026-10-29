import { useCallback } from 'react'
import { useNavigate } from 'react-router'
import { useTranslation } from 'react-i18next'
import { useToastSafe } from '@/contexts/ToastContext'
import { martisEventBus } from '@/lib/eventBus'
import { actionRunUrl, mapActionErrors, postAction, type ActionRequestVia } from '@/components/Actions/actionRequest'
import { handleActionResponse, type ActionResponsePayload } from '@/components/Actions/actionResponse'
import { useActionResponseModal } from '@/components/Actions/ActionResponseModalHost'
import { useRefreshRecord } from './useRefreshRecord'

/** What `runAction()` takes. */
export interface RunActionOptions {
  /** The resource key the action runs on (e.g. `posts`). */
  resource: string
  /** The `uriKey` of the action. */
  action: string
  /** One record to run it on; the same as `ids: [id]`. */
  id?: string | number
  /** Several records (the action's bulk run). `id` wins when both are given. */
  ids?: Array<string | number>
  /** The values for the action's fields. */
  fields?: Record<string, unknown>
  /** The lens the action is declared on, when it runs from one. */
  lens?: string
  /** The relationship a panel runs it through (`viaResource`, `viaResourceId`, `viaRelationship`). */
  via?: ActionRequestVia
  /**
   * Handle the answer as the action modal does (toast, redirect, download,
   * modal, new tab). Default `true`; `false` leaves it to the caller, who
   * reads `response` of the result.
   */
  handleResponse?: boolean
}

/** The outcome of `runAction()`: it resolves, it never rejects. */
export type RunActionResult =
  | { ok: true; response: ActionResponsePayload | undefined }
  | {
      ok: false
      /** The server's message, or the generic failure text. */
      message: string
      /** A 422's errors keyed by the field's attribute (`fields.` prefix removed). */
      errors: Record<string, string>
    }

/** What `useRunAction()` returns. */
export type RunAction = (options: RunActionOptions) => Promise<RunActionResult>

/**
 * Run an action from a custom component (a field's Display, a Tool, a card):
 * the same request and answer handling as the action modal, with the run
 * reported as a result instead of thrown, so a failure can go on screen
 * next to the control that ran it. Emits `martis:action-executed` and
 * refreshes the record (`useRefreshRecord()`) after a handled answer.
 */
export function useRunAction(): RunAction {
  const { t } = useTranslation('actions')
  const { addToast } = useToastSafe()
  const navigate = useNavigate()
  const showModal = useActionResponseModal()
  const refreshRecord = useRefreshRecord()

  return useCallback<RunAction>(
    async ({ resource, action, id, ids, fields, lens, via, handleResponse = true }) => {
      const resources = id !== undefined ? [id] : (ids ?? [])
      let response: ActionResponsePayload | undefined
      try {
        const res = await postAction(actionRunUrl(resource, action, lens), { resources, fields, via })
        response = res?.data
      } catch (err) {
        const errors = mapActionErrors(err)
        const message = (err instanceof Error && err.message) || t('action_failed')
        if (handleResponse) addToast('error', message)
        return { ok: false, message, errors }
      }

      // The action ran: a failure past this point (the answer's handling, a
      // refresh) is the page's, not the action's, so it never reports the run
      // as failed (the caller would retry an action that already ran).
      try {
        martisEventBus.emit('martis:action-executed', { resourceKey: resource, action, ids: resources.map(String) })
        if (handleResponse) {
          handleActionResponse(response, {
            t,
            addToast,
            navigate,
            hide: () => {},
            refresh: () => (resources.length === 1 ? refreshRecord(resource, resources[0]) : refreshRecord(resource)),
            showModal,
          })
        }
      } catch (err) {
        console.error('Handling the answer of a successful action run failed.', err)
      }
      return { ok: true, response }
    },
    [t, addToast, navigate, showModal, refreshRecord],
  )
}
