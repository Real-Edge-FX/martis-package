import { Dialog } from 'primereact/dialog'
import { useTranslation } from 'react-i18next'
import { LockKeyIcon, XIcon } from '@phosphor-icons/react'
import { ResourceIcon } from '@/components/ResourceIcon'
import { useGate } from '@/contexts/GateContext'

/**
 * Soft-gate modal — opens whenever a user clicks a locked entry in
 * the sidebar or lands on a route that the server-side guard
 * answered with `{ locked: true, lock: ... }`.
 *
 * The component is fully data-driven — title/message/CTA copy comes
 * from the entity's `lockModal(...)` config (or `lockPreset`). Theming
 * uses Martis CSS vars so the dialog inherits the panel's accent and
 * surface colours rather than PrimeReact's defaults.
 *
 * Rendered once at the shell level (mounted by `app.tsx` inside the
 * `<GateProvider>`); the singleton state in `GateContext` decides
 * whether it's visible.
 *
 * v1.11.0+.
 */
export function GateModal() {
  const { t } = useTranslation('messages')
  const { isOpen, lock, close } = useGate()

  if (!isOpen || lock === null || lock.modal === null) return null

  const modal = lock.modal
  const title = modal.title ?? t('gate.default_title', 'Locked feature')
  const message = modal.message ?? t('gate.default_message', 'This feature is not available on your current plan.')
  const cta = modal.cta ?? null
  const dismissible = modal.dismiss !== false
  const iconName = modal.icon ?? null

  return (
    <Dialog
      visible={isOpen}
      modal
      closable={dismissible}
      dismissableMask={dismissible}
      onHide={close}
      // PrimeReact's default close glyph is a PrimeIcons font character
      // (`pi pi-times`); Martis ships only Phosphor and does not load the
      // PrimeIcons font, so the default header X renders as an empty
      // hover-circle. Pass an explicit Phosphor icon so the close button
      // is visible in every consumer regardless of font setup.
      //
      // The inline `color: var(--martis-text)` override is intentional:
      // PrimeReact's bundled CSS resets `.p-dialog-header-close` to
      // `rgba(255, 255, 255, 0.6)` (assumes a dark tabbed surface), which
      // disappears on Martis's light/neutral header. Routing the SVG fill
      // through Martis's text token makes the X visible in every theme.
      closeIcon={<XIcon size={18} weight="bold" color="var(--martis-text)" />}
      header={(
        <div className="flex items-center gap-2" style={{ color: 'var(--martis-text)' }}>
          {iconName !== null ? (
            <ResourceIcon iconName={iconName} size={20} />
          ) : (
            <LockKeyIcon size={20} />
          )}
          <span className="font-semibold">{title}</span>
        </div>
      )}
      style={{
        width: 'min(440px, 90vw)',
        backgroundColor: 'var(--martis-surface)',
        color: 'var(--martis-text)',
      }}
      contentStyle={{
        backgroundColor: 'var(--martis-surface)',
        color: 'var(--martis-text)',
        borderColor: 'var(--martis-border)',
      }}
      headerStyle={{
        backgroundColor: 'var(--martis-surface)',
        color: 'var(--martis-text)',
        borderBottom: '1px solid var(--martis-border)',
      }}
    >
      <div className="space-y-4">
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

        {cta !== null && (
          <div className="flex justify-end gap-2 pt-2">
            {dismissible && (
              <button
                type="button"
                onClick={close}
                className="rounded-md border px-3 py-1.5 text-sm"
                style={{
                  borderColor: 'var(--martis-border)',
                  color: 'var(--martis-text-muted)',
                  backgroundColor: 'transparent',
                }}
              >
                {t('gate.later', 'Later')}
              </button>
            )}
            <a
              href={cta.url}
              target={cta.target ?? '_self'}
              rel={cta.target === '_blank' ? 'noopener noreferrer' : undefined}
              onClick={close}
              className="inline-flex items-center justify-center rounded-md px-3 py-1.5 text-sm font-medium"
              style={{
                backgroundColor: 'var(--martis-accent)',
                color: '#fff',
              }}
            >
              {cta.label}
            </a>
          </div>
        )}
      </div>
    </Dialog>
  )
}
