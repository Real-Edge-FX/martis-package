import { useState, type ReactNode } from 'react'
import { useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { ArrowLeftIcon, BookOpenIcon, CopyIcon, CheckIcon } from '@phosphor-icons/react'
import { config } from '@/lib/config'

export interface ErrorScreenProps {
  /** HTTP status code displayed as the faint watermark behind the icon. */
  code: string
  /** Large phosphor icon rendered inside the accent-tinted circle. */
  icon: ReactNode
  /** Page title — short, sentence-cased, no trailing period. */
  title: string
  /** Paragraph body describing the situation and next step for the user. */
  description: string
  /** Label for the primary call-to-action (defaults to "Back to dashboard"). */
  primaryLabel?: string
  /** Optional custom handler for the primary CTA — defaults to navigating home. */
  onPrimary?: () => void
  /** Optional href for a secondary CTA — defaults to `config.docsUrl` when set. */
  secondaryHref?: string | null
  /** Label for the secondary CTA (defaults to "Check status"). */
  secondaryLabel?: string
  /** Optional incident id — when provided, renders the copy-id chip under the description. */
  incidentId?: string | null
}

export function ErrorScreen({
  code,
  icon,
  title,
  description,
  primaryLabel,
  onPrimary,
  secondaryHref,
  secondaryLabel,
  incidentId,
}: ErrorScreenProps) {
  const { t } = useTranslation('messages')
  const navigate = useNavigate()
  const [copied, setCopied] = useState(false)

  const resolvedPrimaryLabel = primaryLabel ?? t('error_back_to_dashboard', { defaultValue: 'Back to dashboard' })
  const resolvedSecondaryLabel = secondaryLabel ?? t('error_check_status', { defaultValue: 'Check status' })
  const resolvedSecondaryHref = secondaryHref ?? config.docsUrl ?? null

  async function copyIncidentId() {
    if (!incidentId) return
    try {
      await navigator.clipboard.writeText(incidentId)
      setCopied(true)
      setTimeout(() => setCopied(false), 2000)
    } catch {
      /* clipboard unavailable — ignore */
    }
  }

  function handlePrimary() {
    if (onPrimary) onPrimary()
    else navigate('/')
  }

  return (
    <div className="martis-error-screen">
      <div className="martis-error-code" aria-hidden="true">{code}</div>
      <div className="martis-error-icon">{icon}</div>
      <h2 className="martis-error-title">{title}</h2>
      <p className="martis-error-desc">{description}</p>
      {incidentId && (
        <div className="martis-error-id">
          <span style={{ color: 'var(--martis-text-muted)' }}>
            {t('error_incident_id', { defaultValue: 'Incident id' })}
          </span>
          <span style={{ fontFamily: 'var(--martis-font-mono)' }}>{incidentId}</span>
          <button
            type="button"
            onClick={() => void copyIncidentId()}
            aria-label={t('copy', { defaultValue: 'Copy' })}
            style={{
              display: 'inline-flex',
              alignItems: 'center',
              justifyContent: 'center',
              width: 24,
              height: 24,
              border: 0,
              background: 'transparent',
              borderRadius: 'var(--martis-radius-sm, 4px)',
              color: 'var(--martis-text-muted)',
              cursor: 'pointer',
            }}
          >
            {copied ? <CheckIcon size={12} /> : <CopyIcon size={12} />}
          </button>
        </div>
      )}
      <div style={{ display: 'flex', gap: 8, marginTop: 24, position: 'relative', zIndex: 1 }}>
        <button type="button" onClick={handlePrimary} className="martis-btn-primary">
          <ArrowLeftIcon size={14} weight="bold" />
          {resolvedPrimaryLabel}
        </button>
        {resolvedSecondaryHref && (
          <a
            href={resolvedSecondaryHref}
            target="_blank"
            rel="noreferrer"
            className="martis-btn-secondary"
          >
            <BookOpenIcon size={14} />
            {resolvedSecondaryLabel}
          </a>
        )}
      </div>
    </div>
  )
}
