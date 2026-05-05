import { useEffect, useState } from 'react'
import { createPortal } from 'react-dom'
import { useTranslation } from 'react-i18next'
import { LockKeyIcon, XIcon } from '@phosphor-icons/react'
import { ResourceIcon } from '@/components/ResourceIcon'
import { useGate } from '@/contexts/GateContext'
import { useModalHistoryLock } from '@/lib/historyLock'

/**
 * Soft-gate modal — opens whenever a user clicks a locked entry in
 * the sidebar or lands on a route that the server-side guard
 * answered with `{ locked: true, lock: ... }`.
 *
 * Built on the same `martis-modal-*` primitives as `DeleteModal` so
 * the two surfaces share scrim, surface, header divider, body, and
 * footer divider styling. Theming flows through Martis CSS vars
 * (`--martis-text`, `--martis-accent`, etc.) — no PrimeReact Dialog,
 * no PrimeIcons font.
 *
 * The component is fully data-driven — title/message/CTA copy comes
 * from the entity's `lockModal(...)` config (or `lockPreset`).
 *
 * Rendered once at the shell level (mounted by `app.tsx` inside the
 * `<GateProvider>`); the singleton state in `GateContext` decides
 * whether it's visible.
 *
 * v1.11.0+. Rewritten on `martis-modal-*` primitives in v1.11.6.
 */
export function GateModal() {
  const { t } = useTranslation('messages')
  const { isOpen, lock, close } = useGate()
  const [visible, setVisible] = useState(false)

  useEffect(() => {
    if (isOpen) {
      requestAnimationFrame(() => setVisible(true))
    } else {
      setVisible(false)
    }
  }, [isOpen])

  useEffect(() => {
    if (!isOpen) return
    function handleKey(e: KeyboardEvent) {
      if (e.key === 'Escape') close()
    }
    document.addEventListener('keydown', handleKey)
    return () => document.removeEventListener('keydown', handleKey)
  }, [isOpen, close])

  // Block the browser back button while the modal is visible — the
  // user must dismiss explicitly (Later) or follow the CTA. Mirrors
  // DeleteModal's behaviour.
  useModalHistoryLock(isOpen)

  if (!isOpen || lock === null || lock.modal === null) return null

  const modal = lock.modal
  const title = modal.title ?? t('gate.default_title', 'Locked feature')
  const message = modal.message ?? t('gate.default_message', 'This feature is not available on your current plan.')
  const cta = modal.cta ?? null
  const dismissible = modal.dismiss !== false
  const iconName = modal.icon ?? null

  function handleBackdropClose() {
    if (!dismissible) return
    setVisible(false)
    setTimeout(close, 200)
  }

  const content = (
    <div
      className="martis-modal-scrim"
      style={{ opacity: visible ? 1 : 0, transition: 'opacity 200ms ease' }}
      onClick={handleBackdropClose}
    >
      <div
        role="dialog"
        aria-modal="true"
        className="martis-modal-surface"
        style={{
          transform: visible ? 'scale(1)' : 'scale(0.95)',
          transition: 'transform 200ms ease',
        }}
        onClick={(e) => e.stopPropagation()}
      >
        <div className="martis-modal-head">
          <div className="flex items-center gap-3">
            {iconName !== null ? (
              <ResourceIcon iconName={iconName} size={18} />
            ) : (
              <LockKeyIcon size={18} weight="bold" />
            )}
            <h3 className="martis-modal-head-title">{title}</h3>
          </div>
          {dismissible && (
            <button
              type="button"
              onClick={close}
              className="martis-modal-close"
              aria-label={t('gate.later', 'Later')}
            >
              <XIcon size={16} />
            </button>
          )}
        </div>

        <div className="martis-modal-body">
          {modal.messageHtml === true ? (
            <p
              className="text-sm leading-relaxed"
              style={{ color: 'var(--martis-text-muted)' }}
              // Trusted source: the message comes from PHP-side config,
              // never from user input. Hosts that put untrusted text here
              // are responsible for sanitising upstream.
              dangerouslySetInnerHTML={{ __html: message }}
            />
          ) : (
            <p
              className="text-sm leading-relaxed"
              style={{ color: 'var(--martis-text-muted)' }}
            >
              {message}
            </p>
          )}
        </div>

        {cta !== null && (
          <div className="martis-modal-foot">
            {dismissible && (
              <button
                type="button"
                onClick={close}
                className="martis-btn-secondary"
              >
                {t('gate.later', 'Later')}
              </button>
            )}
            <a
              href={cta.url}
              target={cta.target ?? '_self'}
              rel={cta.target === '_blank' ? 'noopener noreferrer' : undefined}
              onClick={close}
              className="martis-btn-primary"
            >
              {cta.label}
            </a>
          </div>
        )}
      </div>
    </div>
  )

  return createPortal(content, document.body)
}
