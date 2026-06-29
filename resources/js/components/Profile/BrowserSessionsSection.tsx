import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Badge } from 'primereact/badge'
import { DesktopIcon, DeviceMobileIcon, MonitorIcon, SignOutIcon, TrashIcon } from '@phosphor-icons/react'
import { api, ApiError } from '@/lib/api'
import { useToast } from '@/contexts/ToastContext'

/**
 * Browser sessions surface — `Profile > Browser sessions`. Lists every
 * session row Laravel's database session driver wrote for the current
 * user and lets them revoke a single device or every device except the
 * current one.
 *
 * The endpoint short-circuits with `supported: false` when the host
 * app uses a non-database driver (file / cookie / array). This UI
 * renders a one-line hint in that case, so the panel still appears
 * but the user understands why it is empty.
 */

interface BrowserSession {
  id: string
  ip_address: string
  user_agent: string
  last_active: number
  is_current: boolean
}

interface SessionsResponse {
  sessions: BrowserSession[]
  supported: boolean
  driver: string
}

function deviceIconFor(userAgent: string): JSX.Element {
  const ua = userAgent.toLowerCase()
  if (ua.includes('mobile') || ua.includes('iphone') || ua.includes('android')) {
    return <DeviceMobileIcon size={20} className="martis-text-muted" />
  }
  if (ua.includes('tablet') || ua.includes('ipad')) {
    return <MonitorIcon size={20} className="martis-text-muted" />
  }
  return <DesktopIcon size={20} className="martis-text-muted" />
}

function deviceLabel(userAgent: string): string {
  if (!userAgent) return 'Unknown device'

  // Best-effort parse — we want a short label, not a full UA string.
  const browserMatch = userAgent.match(/(Chrome|Safari|Firefox|Edg|Opera)\/?[\d.]*/i)
  const osMatch = userAgent.match(/(Windows|Mac OS X|Linux|Android|iOS|iPhone|iPad)/i)

  const browser = browserMatch?.[1] ?? 'Browser'
  const os = osMatch?.[1] ?? ''

  return os ? `${browser} on ${os}` : browser
}

function relativeTime(timestamp: number, locale = 'en'): string {
  const seconds = Math.floor(Date.now() / 1000) - timestamp
  const rtf = new Intl.RelativeTimeFormat(locale, { numeric: 'auto' })
  if (seconds < 60) return rtf.format(-seconds, 'second')
  if (seconds < 3600) return rtf.format(-Math.floor(seconds / 60), 'minute')
  if (seconds < 86400) return rtf.format(-Math.floor(seconds / 3600), 'hour')

  return new Intl.DateTimeFormat(locale, {
    dateStyle: 'medium',
    timeStyle: 'short',
  }).format(new Date(timestamp * 1000))
}

export function BrowserSessionsSection(): JSX.Element {
  const { t, i18n } = useTranslation('profile')
  const { addToast } = useToast()
  const [sessions, setSessions] = useState<BrowserSession[]>([])
  const [supported, setSupported] = useState(true)
  const [loading, setLoading] = useState(true)
  const [revokingAll, setRevokingAll] = useState(false)
  const [revokingId, setRevokingId] = useState<string | null>(null)

  async function load(): Promise<void> {
    setLoading(true)
    try {
      const res = await api.get<SessionsResponse>('/api/profile/sessions')
      setSessions(res.sessions ?? [])
      setSupported(res.supported)
    } catch {
      addToast('error', t('error', { defaultValue: 'Could not load sessions.' }))
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    void load()
  }, [])

  async function handleRevokeOthers(): Promise<void> {
    if (!confirm(t('sessions_revoke_others_confirm', {
      defaultValue: 'Sign out of every other device? You will stay signed in here.',
    }))) {
      return
    }
    setRevokingAll(true)
    try {
      const res = await api.delete<{ revoked: number; supported: boolean }>('/api/profile/sessions/others')
      addToast('success', t('sessions_revoke_others_success', {
        defaultValue: 'Signed out of {{count}} session(s).',
        count: res.revoked,
      }))
      await load()
    } catch (err) {
      const msg = err instanceof ApiError && err.message ? err.message : t('error')
      addToast('error', msg)
    } finally {
      setRevokingAll(false)
    }
  }

  async function handleRevoke(id: string): Promise<void> {
    setRevokingId(id)
    try {
      await api.delete(`/api/profile/sessions/${encodeURIComponent(id)}`)
      addToast('success', t('sessions_revoke_success', {
        defaultValue: 'Session revoked.',
      }))
      await load()
    } catch (err) {
      const msg = err instanceof ApiError && err.message ? err.message : t('error')
      addToast('error', msg)
    } finally {
      setRevokingId(null)
    }
  }

  return (
    <section
      className="rounded-xl p-6 border martis-border martis-card-bg"
      aria-labelledby="sessions-section-title"
    >
      <div className="flex items-start justify-between gap-4 flex-wrap mb-4">
        <div>
          <h2 id="sessions-section-title" className="text-lg font-semibold martis-text">
            {t('sessions_title', { defaultValue: 'Browser sessions' })}
          </h2>
          <p className="text-sm martis-text-muted mt-1">
            {t('sessions_subtitle', {
              defaultValue: 'Devices that are signed in to your account.',
            })}
          </p>
        </div>
        {supported && sessions.length > 1 && (
          <button
            type="button"
            onClick={() => void handleRevokeOthers()}
            disabled={revokingAll}
            className="martis-btn-secondary"
          >
            <SignOutIcon size={14} />
            {revokingAll
              ? t('sessions_revoking', { defaultValue: 'Revoking…' })
              : t('sessions_revoke_others', { defaultValue: 'Sign out everywhere else' })}
          </button>
        )}
      </div>

      {!supported && (
        <p className="text-sm martis-text-muted">
          {t('sessions_unsupported', {
            defaultValue: 'Browser-session management requires the database session driver.',
          })}
        </p>
      )}

      {supported && loading && (
        <p className="text-sm martis-text-muted">{t('sessions_loading', { defaultValue: 'Loading…' })}</p>
      )}

      {supported && !loading && sessions.length === 0 && (
        <p className="text-sm martis-text-muted">
          {t('sessions_empty', { defaultValue: 'No active sessions found.' })}
        </p>
      )}

      {supported && !loading && sessions.length > 0 && (
        <ul className="flex flex-col gap-2">
          {sessions.map((session) => (
            <li
              key={session.id}
              className="flex items-center justify-between gap-4 p-3 border martis-border rounded-lg"
            >
              <div className="flex items-center gap-3">
                {deviceIconFor(session.user_agent)}
                <div>
                  <div className="flex items-center gap-2">
                    <p className="text-sm font-medium martis-text">{deviceLabel(session.user_agent)}</p>
                    {session.is_current && (
                      <Badge
                        value={t('sessions_current_badge', { defaultValue: 'This device' })}
                        severity="success"
                      />
                    )}
                  </div>
                  <p className="text-xs martis-text-muted mt-0.5">
                    {session.ip_address || t('sessions_unknown_ip', { defaultValue: 'Unknown IP' })}
                    {' · '}
                    {relativeTime(session.last_active, i18n.language)}
                  </p>
                </div>
              </div>
              {!session.is_current && (
                <button
                  type="button"
                  onClick={() => void handleRevoke(session.id)}
                  disabled={revokingId === session.id}
                  className="martis-btn-ghost martis-btn-icon"
                  aria-label={t('sessions_revoke', { defaultValue: 'Revoke session' })}
                  data-pr-tooltip={t('sessions_revoke', { defaultValue: 'Revoke session' })}
                >
                  <TrashIcon size={14} />
                </button>
              )}
            </li>
          ))}
        </ul>
      )}
    </section>
  )
}
