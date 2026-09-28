import { useTranslation } from 'react-i18next'
import { WrenchIcon } from '@phosphor-icons/react'
import type { LockedToolResponse } from '@/types'

/**
 * What `/tools/{uriKey}` and a registered page bound to a Tool render when
 * `GET /api/tools/{uriKey}` answers 404: the Tool does not exist, or its
 * `canSee()` hides it from the user. The API does not tell the two apart,
 * so a user cannot probe which tools exist.
 */
export function ToolHiddenState() {
  const { t } = useTranslation('messages')

  return (
    <div className="martis-tool-empty" role="status">
      <WrenchIcon size={36} weight="duotone" />
      <h1>{t('tool_not_found_title', 'Tool not found')}</h1>
      <p>{t('tool_not_found_body', 'This tool does not exist or you do not have permission to see it.')}</p>
    </div>
  )
}

/**
 * The full-page soft-gate state (v1.11.0+): the route guard answered
 * `{ locked: true, lock, tool }` and `useToolDescriptor` opened the
 * GateModal. Renders the locked card with the upsell CTA.
 */
export function ToolLockedState({ payload }: { payload: LockedToolResponse }) {
  const { t } = useTranslation('messages')
  const modal = payload.lock.modal

  return (
    <div
      className="flex flex-col items-center justify-center rounded-lg border border-dashed py-16 text-center"
      style={{
        backgroundColor: 'var(--martis-surface)',
        borderColor: 'var(--martis-border)',
        color: 'var(--martis-text-muted)',
      }}
    >
      <WrenchIcon size={36} weight="duotone" />
      <h1 className="mt-3 text-lg font-semibold" style={{ color: 'var(--martis-text)' }}>
        {modal?.title ?? t('gate.default_title', 'Locked feature')}
      </h1>
      <p className="mt-2 max-w-md text-sm">
        {modal?.message ?? t('gate.default_message', 'This tool is not available on your current plan.')}
      </p>
      {modal?.cta && (
        <a
          href={modal.cta.url}
          target={modal.cta.target ?? '_self'}
          rel={modal.cta.target === '_blank' ? 'noopener noreferrer' : undefined}
          className="mt-4 inline-flex items-center justify-center rounded-md px-4 py-2 text-sm font-medium"
          style={{ backgroundColor: 'var(--martis-accent)', color: 'var(--martis-accent-contrast, #ffffff)' }}
        >
          {modal.cta.label}
        </a>
      )}
    </div>
  )
}
