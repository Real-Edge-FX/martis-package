import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { ArrowUUpLeftIcon, UserCircleIcon, UserSwitchIcon } from '@phosphor-icons/react'
import { api, ApiError } from '@/lib/api'
import { useToast } from '@/contexts/ToastContext'

interface ImpersonationSnapshot {
  active: boolean
  enabled: boolean
  original: { id: number | string | null; label: string | null } | null
  target: { id: number | string | null; label: string | null } | null
  started_at: string | null
}

/**
 * Built-in banner for the v0.10 Impersonation primitive.
 *
 * Polls `/api/impersonation/status` once on mount, then every 30s,
 * and renders a fixed banner at the top of the layout when an
 * impersonation session is active. The "Stop impersonating" button
 * POSTs to `/api/impersonation/stop` and reloads the page so the
 * SPA re-bootstraps as the original user.
 *
 * Renders nothing when:
 *   - the master switch (`martis.impersonation.enabled`) is OFF
 *   - no impersonation is currently active
 *
 * The component is mounted unconditionally inside `Layout` — the
 * 503 / inactive snapshot fallthroughs are silent so the surface
 * is invisible by default. Apps that want to hide the banner
 * entirely can override `martis:layout-shell` and skip rendering it.
 */
export function ImpersonationBanner() {
  const { t } = useTranslation('messages')
  const { addToast } = useToast()
  const [snap, setSnap] = useState<ImpersonationSnapshot | null>(null)
  const [busy, setBusy] = useState(false)

  useEffect(() => {
    let cancelled = false
    let interval: number | null = null

    const fetchOnce = async () => {
      try {
        const data = await api.get<ImpersonationSnapshot>('/api/impersonation/status')
        if (cancelled) return

        // Master switch is off — stop polling. The endpoint returns
        // a steady `enabled: false` payload until the host app flips
        // the config, and a per-page-navigation refresh covers the
        // (rare) case where the operator turned it on mid-session.
        if (!data.enabled) {
          if (interval !== null) {
            window.clearInterval(interval)
            interval = null
          }
          setSnap(data)
          return
        }

        setSnap(data)
      } catch {
        // The endpoint returns 503 when the master switch is off — we
        // intentionally swallow it so the banner stays invisible. Stop
        // polling since this state cannot change without a page reload.
        if (cancelled) return
        if (interval !== null) {
          window.clearInterval(interval)
          interval = null
        }
        setSnap(null)
      }
    }

    fetchOnce()
    interval = window.setInterval(fetchOnce, 30_000)

    return () => {
      cancelled = true
      if (interval !== null) {
        window.clearInterval(interval)
      }
    }
  }, [])

  if (!snap?.active) return null

  const stop = async () => {
    setBusy(true)
    try {
      await api.post('/api/impersonation/stop', {})
      // Hard reload — the auth user changed at the framework level,
      // every cached query needs to refetch with the operator's policies.
      window.location.reload()
    } catch (e) {
      const message = e instanceof ApiError ? e.errorSummary() : t('impersonation_stop_failed', 'Could not stop the impersonation session.')
      addToast('error', message)
      setBusy(false)
    }
  }

  return (
    <div className="martis-impersonation-banner" role="alert" aria-live="polite">
      <div className="martis-impersonation-banner-icon">
        <UserSwitchIcon size={20} weight="duotone" />
      </div>
      <div className="martis-impersonation-banner-text">
        <strong>
          {t('impersonation_banner_title', 'Impersonation active')}
        </strong>
        <span>
          {t('impersonation_banner', {
            defaultValue: 'You are impersonating {{target}} (signed in as {{original}}).',
            target: snap.target?.label ?? '?',
            original: snap.original?.label ?? '?',
          })}
        </span>
      </div>
      <button
        type="button"
        className="martis-impersonation-banner-stop"
        onClick={stop}
        disabled={busy}
      >
        <ArrowUUpLeftIcon size={16} weight="bold" />
        {t('impersonation_stop', 'Stop impersonating')}
      </button>
      <span className="martis-impersonation-banner-target" aria-hidden="true">
        <UserCircleIcon size={16} weight="duotone" />
        {snap.target?.label ?? '?'}
      </span>
    </div>
  )
}
